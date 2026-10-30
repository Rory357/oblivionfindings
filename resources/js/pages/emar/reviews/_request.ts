export type ReviewRequestData = Record<
    string,
    string | number | boolean | File | null
>;
export class ReviewRequestError extends Error {
    constructor(
        public readonly errors: Record<string, string>,
        public readonly status: number | null = null,
    ) {
        super(Object.values(errors).join(' '));
        this.name = 'ReviewRequestError';
    }
}

/** Same-origin JSON/CSRF pattern used by personal-calendar.ts, with staged-file support. */
export async function reviewRequest(
    url: string,
    data: ReviewRequestData,
    method: 'post' | 'put' | 'delete' = 'post',
): Promise<{ saved: true; message?: string; review_id?: number }> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const hasFile = Object.values(data).some((value) => value instanceof File);
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token
            ? { 'X-XSRF-TOKEN': decodeURIComponent(token) }
            : csrf
              ? { 'X-CSRF-TOKEN': csrf }
              : {}),
    };
    let body: FormData | string;
    if (hasFile) {
        body = new FormData();
        for (const [key, value] of Object.entries(data)) {
            if (value instanceof File) body.append(key, value);
            else
                body.append(
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
        if (method !== 'post') body.append('_method', method.toUpperCase());
    } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(data);
    }
    let response: Response;
    try {
        response = await fetch(url, {
            method: hasFile ? 'POST' : method.toUpperCase(),
            credentials: 'same-origin',
            cache: 'no-store',
            headers,
            body,
        });
    } catch {
        throw new ReviewRequestError({
            _request:
                'We couldn’t confirm the save. Your entries and selected file are retained. Check your connection and refresh the review before retrying.',
        });
    }
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (
            response.status === 422 &&
            result.errors &&
            typeof result.errors === 'object'
        ) {
            const errors: Record<string, string> = {};
            for (const [key, value] of Object.entries(result.errors))
                errors[key] = Array.isArray(value)
                    ? value.join(' ')
                    : String(value);
            throw new ReviewRequestError(errors, 422);
        }
        const message =
            response.status === 409
                ? 'This review changed while you were editing. Your entries are retained. Refresh the review data, check every entry, then try again.'
                : response.status === 403 || response.status === 404
                  ? 'This action is no longer available with your access. Your entries are retained; nothing has been confirmed as saved.'
                  : response.status === 419 || response.status === 401
                    ? 'Your sign-in session expired. Your entries are retained in this open form. Sign in again before retrying.'
                    : response.status === 503
                      ? 'The review’s follow-up service is unavailable. Your entries and file are retained. Try again once it is available.'
                      : 'We couldn’t confirm the save. Your entries and file are retained. Refresh the review before retrying.';
        throw new ReviewRequestError({ _request: message }, response.status);
    }
    if (result.saved !== true)
        throw new ReviewRequestError({
            _request:
                'We couldn’t confirm that this was saved. Keep this form open and refresh the review before retrying.',
        });
    return result;
}
