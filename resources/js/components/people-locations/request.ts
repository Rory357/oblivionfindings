export function csrfHeaders() {
    const token = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '',
    };
}
export async function checkResponse(
    response: Response,
    expected = 'application/json',
) {
    if (response.redirected || [401, 403, 419].includes(response.status))
        throw new Error(
            'Your access could not be verified. Refresh the workspace and sign in if required.',
        );
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(
            Object.values(data.errors ?? {})
                .flat()
                .join(' ') ||
                data.message ||
                'The request failed. Your entries are retained; try again.',
        );
    }
    if (!response.headers.get('content-type')?.includes(expected))
        throw new Error(
            'The server returned an unexpected response. No file was downloaded. Try again after refreshing.',
        );
}
