export async function hubRequest<T>(
    url: string,
    method: string,
    data?: unknown,
): Promise<T> {
    const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';
    const form = data instanceof FormData;
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
            ...(!form ? { 'Content-Type': 'application/json' } : {}),
        },
        body: method === 'GET' ? undefined : form ? data : JSON.stringify(data),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const messages = result.errors
            ? Object.values(result.errors).flat().join(' ')
            : result.message;
        throw new Error(
            messages ||
                (response.status === 419
                    ? 'Your session has expired. Reload the page to sign in again.'
                    : 'Your changes could not be saved. Please try again.'),
        );
    }
    return result as T;
}
