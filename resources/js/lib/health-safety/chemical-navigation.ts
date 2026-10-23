import { injuryReturn, INJURY_REGISTER } from './injury-navigation';
import { exposureLeaf } from './chemical-exposure-leaf';

export const CHEMICAL_REGISTER = '/health-safety/substances';
export const CHEMICAL_SECTIONS = ['overview', 'controls', 'sds', 'storage', 'history', 'exposures'] as const;
export type ChemicalSection = (typeof CHEMICAL_SECTIONS)[number];
export const CHEMICAL_ACTIONS = ['edit', 'status', 'add_sds', 'edit_sds', 'current_sds', 'add_storage', 'edit_storage', 'audit_storage', 'archive_storage', 'restore_storage'] as const;
export type ChemicalAction = (typeof CHEMICAL_ACTIONS)[number];
const origin = 'https://chemical-navigation.invalid';
const positive = (value: string | null) => value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null;
const single = (query: URLSearchParams, key: string) => query.getAll(key).length === 1 ? query.get(key) : null;
function parsed(value: string | null | undefined): URL | null {
    if (!value || new TextEncoder().encode(value).length > 1500 || !value.startsWith('/') || value.startsWith('//') || /[\\#\u0000-\u0020\u007f]/.test(value)) return null;
    try {
        const target = new URL(value, origin);
        if (target.origin !== origin || target.pathname !== value.split('?')[0] || /[\\\u0000-\u001f\u007f]/.test(decodeURIComponent(value))) return null;
        return target;
    } catch { return null; }
}
/** Navigation hints are bounded leaves; source admission belongs to each destination. */
export function chemicalReturn(value: string | null | undefined): string {
    const privateLeaf = exposureLeaf(value); if (privateLeaf !== null) return privateLeaf;
    const target = parsed(value);
    if (!target) return CHEMICAL_REGISTER;
    if (target.pathname !== CHEMICAL_REGISTER) {
        target.searchParams.delete('return_to');
        const safe = injuryReturn(target.pathname + target.search);
        return safe === INJURY_REGISTER && target.pathname !== INJURY_REGISTER ? CHEMICAL_REGISTER : safe;
    }
    const out = new URLSearchParams();
    const enums: Record<string, readonly string[]> = {
        tab: ['all', 'active', 'controlled', 'sds_expiring', 'sds_missing', 'inactive'],
        physical_form: ['solid', 'liquid', 'gas', 'powder', 'aerosol', 'paste', 'other'],
        status: ['active', 'inactive', 'removed'], controlled: ['controlled', 'standard'],
        sds_state: ['current', 'expired', 'expiring', 'missing', 'unknown', 'ambiguous', 'not_yet_effective', 'repair_required', 'unavailable'],
    };
    for (const [key, values] of Object.entries(enums)) {
        const item = single(target.searchParams, key); if (item && values.includes(item)) out.set(key, item);
    }
    for (const key of ['site_id', 'page']) { const item = positive(single(target.searchParams, key)); if (item !== null && item <= 2147483647) out.set(key, String(item)); }
    const period = positive(single(target.searchParams, 'period')); if (period !== null && period >= 7 && period <= 365) out.set('period', String(period));
    const q = single(target.searchParams, 'q'); if (q && new TextEncoder().encode(q).length <= 200) out.set('q', q);
    return CHEMICAL_REGISTER + (out.size ? `?${out}` : '');
}
export function chemicalLocation(value: string) {
    const query = parsed(value)?.searchParams ?? new URLSearchParams();
    return { section: CHEMICAL_SECTIONS.find((item) => item === single(query, 'section')) ?? 'overview',
        action: CHEMICAL_ACTIONS.find((item) => item === single(query, 'action')) ?? null,
        storage: positive(single(query, 'storage')), sds: positive(single(query, 'sds')) };
}
export function chemicalHref(id: number, options: { section?: ChemicalSection; action?: ChemicalAction; storage?: number; sds?: number; returnTo?: string } = {}) {
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview') query.set('section', options.section);
    if (options.action) query.set('action', options.action);
    if (options.storage) query.set('storage', String(options.storage));
    if (options.sds) query.set('sds', String(options.sds));
    if (options.returnTo) query.set('return_to', chemicalReturn(options.returnTo));
    return `${CHEMICAL_REGISTER}/${id}${query.size ? `?${query}` : ''}`;
}
