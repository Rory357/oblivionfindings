/** Read-only navigation hints shared by public and private chemical workspaces. No source authority. */
export const CHEMICAL_EXPOSURES = '/health-safety/chemical-exposures';
export const EXPOSURE_SECTIONS = ['overview', 'reviews', 'followups', 'evidence', 'history', 'connections'] as const;
export const EXPOSURE_PAGES = ['history_page', 'reviews_page', 'followups_page', 'evidence_page'] as const;
const origin = 'https://exposure-navigation.invalid';
const single = (query: URLSearchParams, key: string) => query.getAll(key).length === 1 ? query.get(key) : null;
export const positiveExposureIdentity = (value: string | null): number | null =>
    value && /^[1-9]\d*$/.test(value) && value.length <= 10 && Number(value) <= 2147483647 ? Number(value) : null;
export function parseExposureNavigation(value: string | null | undefined): URL | null {
    if (!value || new TextEncoder().encode(value).length > 1500 || !value.startsWith('/') || value.startsWith('//')
        // eslint-disable-next-line no-control-regex -- refuses control characters and spaces in a return link on purpose (link safety).
        || /[\\#\u0000-\u0020\u007f]/.test(value) || /%(?![0-9a-f]{2})/i.test(value)) return null;
    try {
        const target = new URL(value, origin);
        if (target.origin !== origin || target.pathname !== value.split('?')[0]) return null;
        // eslint-disable-next-line no-control-regex -- refuses control characters in a query key or value on purpose (return-link safety).
        for (const [key, item] of target.searchParams) if (/[[\]]/.test(key) || /[\\\u0000-\u001f\u007f]/.test(key + item)) return null;
        return target;
    } catch { return null; }
}
function instant(value: string | null): value is string {
    if (!value || value.length > 64) return false;
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))$/.exec(value);
    if (!match || Number(match[1]) === 0 || Number(match[4]) > 23 || Number(match[5]) > 59 || Number(match[6]) > 59) return false;
    const date = new Date(value.slice(0, 10) + 'T00:00:00Z');
    if (Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value.slice(0, 10)) return false;
    return match[7] === 'Z' || (Number(match[8]) <= 14 && Number(match[9]) <= 59 && (Number(match[8]) !== 14 || Number(match[9]) === 0));
}
export function exposureLeaf(value: string | null | undefined): string | null {
    const target = parseExposureNavigation(value); if (!target) return null;
    const out = new URLSearchParams();
    if (target.pathname === CHEMICAL_EXPOSURES) {
        for (const key of ['site_id', 'worker_id', 'substance_id', 'page']) { const item = positiveExposureIdentity(single(target.searchParams, key)); if (item !== null) out.set(key, String(item)); }
        for (const key of ['from', 'to']) { const item = single(target.searchParams, key); if (instant(item)) out.set(key, item); }
    } else {
        const match = /^\/health-safety\/chemical-exposures\/([1-9]\d*)$/.exec(target.pathname);
        if (!match || positiveExposureIdentity(match[1]) === null) return null;
        const section = single(target.searchParams, 'section'); if (EXPOSURE_SECTIONS.some((item) => item === section)) out.set('section', section!);
        for (const key of EXPOSURE_PAGES) { const item = positiveExposureIdentity(single(target.searchParams, key)); if (item !== null) out.set(key, String(item)); }
    }
    return target.pathname + (out.size ? '?' + out : '');
}
