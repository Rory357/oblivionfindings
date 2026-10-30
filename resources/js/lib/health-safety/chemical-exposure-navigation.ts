import { CHEMICAL_REGISTER, CHEMICAL_SECTIONS, chemicalReturn } from './chemical-navigation';
import { INJURY_REGISTER, injuryReturn } from './injury-navigation';
import { CHEMICAL_EXPOSURES, EXPOSURE_SECTIONS, EXPOSURE_PAGES, exposureLeaf, parseExposureNavigation as parsed, positiveExposureIdentity as positive } from './chemical-exposure-leaf';

/** Private workspace navigation only. Every destination independently admits its current source. */
export { CHEMICAL_EXPOSURES, EXPOSURE_SECTIONS, EXPOSURE_PAGES } from './chemical-exposure-leaf';
export const EXPOSURE_ACTIONS = ['correct', 'review', 'assign_followup', 'reassign_followup', 'complete_followup', 'upload_evidence', 'remove_evidence'] as const;
export type ExposureSection = (typeof EXPOSURE_SECTIONS)[number];
export type ExposureAction = (typeof EXPOSURE_ACTIONS)[number];
export type ExposurePageKey = (typeof EXPOSURE_PAGES)[number];
const single = (query: URLSearchParams, key: string) => query.getAll(key).length === 1 ? query.get(key) : null;
const url = (path: string, query: URLSearchParams) => path + (query.size ? '?' + query.toString() : '');
/** No command, worker narrative, UUID or nested return survives a Back hint. */
export function exposureReturn(value: string | null | undefined): string {
    const leaf = exposureLeaf(value); if (leaf !== null) return leaf;
    const target = parsed(value);
    if (!target) return CHEMICAL_EXPOSURES;
    const out = new URLSearchParams();
    if (target.pathname === CHEMICAL_REGISTER) return chemicalReturn(url(target.pathname, target.searchParams));
    const chemical = /^\/health-safety\/substances\/([1-9]\d*)$/.exec(target.pathname);
    if (chemical && positive(chemical[1]) !== null) {
        const section = single(target.searchParams, 'section');
        if (CHEMICAL_SECTIONS.some((item) => item === section)) out.set('section', section!);
        return url(target.pathname, out);
    }
    for (const key of ['receipt_key', 'idempotency_key', 'return_to', 'action', 'command', 'upload', 'new', 'section', 'evidence', 'followup']) target.searchParams.delete(key);
    const source = injuryReturn(url(target.pathname, target.searchParams));
    return source === INJURY_REGISTER && target.pathname !== INJURY_REGISTER ? CHEMICAL_EXPOSURES : source;
}
export function exposureLocation(value: string) {
    const query = parsed(value)?.searchParams ?? new URLSearchParams();
    return { section: EXPOSURE_SECTIONS.find((item) => item === single(query, 'section')) ?? 'overview',
        action: EXPOSURE_ACTIONS.find((item) => item === single(query, 'action')) ?? null,
        followup: positive(single(query, 'followup')), evidence: positive(single(query, 'evidence')),
        pages: Object.fromEntries(EXPOSURE_PAGES.map((key) => [key, positive(single(query, key)) ?? 1])) as Record<ExposurePageKey, number> };
}
export function exposureHref(id: number, options: { section?: ExposureSection; action?: ExposureAction; followup?: number; evidence?: number; pages?: Partial<Record<ExposurePageKey, number>>; returnTo?: string } = {}) {
    if (positive(String(id)) === null) return CHEMICAL_EXPOSURES;
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview') query.set('section', options.section);
    if (options.action) query.set('action', options.action);
    for (const key of ['followup', 'evidence'] as const) { const item = positive(String(options[key] ?? '')); if (item !== null) query.set(key, String(item)); }
    for (const key of EXPOSURE_PAGES) { const item = positive(String(options.pages?.[key] ?? '')); if (item !== null && item !== 1) query.set(key, String(item)); }
    if (options.returnTo) { const back = exposureReturn(options.returnTo); if (back.split('?')[0] !== CHEMICAL_EXPOSURES + '/' + id) query.set('return_to', back); }
    return url(CHEMICAL_EXPOSURES + '/' + id, query);
}
export function exposureCaptureHref(substanceId: number, returnTo?: string) {
    if (positive(String(substanceId)) === null) return CHEMICAL_REGISTER;
    const query = new URLSearchParams({ substance_id: String(substanceId) });
    if (returnTo) query.set('return_to', exposureReturn(returnTo));
    return url(CHEMICAL_EXPOSURES + '/capture', query);
}
