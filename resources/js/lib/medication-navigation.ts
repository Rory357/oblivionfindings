/** Return only to recognised, local application pages, never an external URL. */
export function medicationReturnTo(
    value: string | null | undefined,
): string | null {
    if (
        !value ||
        value.length > 2048 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        value.includes('\\') ||
        [...value].some((character) => character.charCodeAt(0) < 32)
    )
        return null;
    try {
        const decoded = decodeURIComponent(value.split(/[?#]/)[0]);
        if (
            decoded.includes('\\') ||
            decoded.startsWith('//') ||
            decoded.split('/').some((part) => part === '.' || part === '..')
        )
            return null;
        const url = new URL(value, 'https://medication.invalid');
        if (
            url.origin !== 'https://medication.invalid' ||
            !/^\/(?:emar(?:\/[a-z0-9_-]+)*|meds\/today|my-day|my-calendar|calendar|attendance|clients\/\d+|tasks|sites\/\d+(?:\/calendar)?|operations\/(?:clients|shifts|handovers)(?:\/\d+)?|health-clinical(?:\/[a-z0-9_-]+)*)\/?$/.test(
                url.pathname,
            )
        )
            return null;
        url.searchParams.delete('return_to');
        return url.pathname + url.search + url.hash;
    } catch {
        return null;
    }
}

/** Attach the originating task without nesting a chain of return URLs. */
export function withMedicationReturn(href: string, source: string): string {
    const url = new URL(href, 'https://medication.invalid');
    const back = medicationReturnTo(source);
    if (back) url.searchParams.set('return_to', back);
    return url.pathname + url.search + url.hash;
}

export function medicationReturnParams(
    source = typeof window === 'undefined' ? '' : window.location.href,
): { return_to?: string } {
    const back = medicationReturnTo(
        new URL(source, 'https://medication.invalid').searchParams.get(
            'return_to',
        ),
    );
    return back ? { return_to: back } : {};
}
