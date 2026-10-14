function csrf(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='));
    const token = cookie
        ? decodeURIComponent(cookie.slice(11))
        : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
              ?.content;
    if (!token)
        throw Error('Sign in again before creating an installation profile');
    return cookie ? { 'X-XSRF-TOKEN': token } : { 'X-CSRF-TOKEN': token };
}
export async function installationRequest(
    url: string,
    signal: AbortSignal,
    data?: unknown,
): Promise<unknown> {
    if (
        !/^\/security-devices\/(?:devices\/[1-9][0-9]*\/endpoint\/(?:bootstrap-options|bootstrap|revoke)|discovery\/collectors\/installation-(?:options\?site_id=[1-9][0-9]*&platform=(?:windows|linux)|profile))$/.test(
            url,
        )
    )
        throw Error('Invalid installation route');
    const controller = new AbortController(),
        abort = () => controller.abort();
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
    const timer = setTimeout(abort, 15000);
    try {
        const response = await fetch(url, {
            method: data === undefined ? 'GET' : 'POST',
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                ...(data === undefined
                    ? {}
                    : { 'Content-Type': 'application/json', ...csrf() }),
            },
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        if (
            !response.ok ||
            !response.headers.get('Content-Type')?.includes('application/json')
        )
            throw Error('Installation request was not confirmed');
        const reader = response.body?.getReader();
        if (!reader) throw Error('Missing installation response');
        const chunks: Uint8Array[] = [];
        let length = 0;
        try {
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                length += value.length;
                if (length > 16384)
                    throw Error('Installation response too large');
                chunks.push(value);
            }
        } finally {
            await reader.cancel();
        }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) {
            bytes.set(chunk, offset);
            offset += chunk.length;
        }
        return JSON.parse(
            new TextDecoder('utf-8', { fatal: true }).decode(bytes),
        );
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
    }
}
