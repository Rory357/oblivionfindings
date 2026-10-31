import { INJURY_REGISTER, injuryReturn } from './injury-navigation';

export const PPE_REGISTER = '/health-safety/ppe';
export const PPE_REGISTER_TABS = [
    'inv_all', 'inv_available', 'inv_allocated', 'inv_inspection',
    'inv_expiring', 'inv_condemned', 'alloc_active', 'alloc_unack', 'types',
] as const;
export type PpeRecordKind = 'inventory' | 'allocations' | 'inspections';
export const PPE_SECTIONS = ['overview', 'allocations', 'inspections', 'fit-training', 'evidence', 'history'] as const;
export type PpeSection = (typeof PPE_SECTIONS)[number];
export const PPE_COMMANDS = [
    'edit', 'allocate', 'inspect', 'condemn', 'dispose', 'acknowledge',
    'return', 'hold', 'release', 'upload_evidence', 'remove_evidence', 'relocate_evidence',
] as const;
export type PpeCommand = (typeof PPE_COMMANDS)[number];
export const PPE_PAGE_KEYS = ['allocations_page', 'inspections_page', 'attachments_page', 'history_page'] as const;
export type PpePageKey = (typeof PPE_PAGE_KEYS)[number];

const ORIGIN = 'https://ppe-return.invalid';
const bytes = (value: string) => new TextEncoder().encode(value).length;
const unsafe = (value: string, spaces = false) => [...value].some((char) =>
    char.charCodeAt(0) < (spaces ? 33 : 32) || char.charCodeAt(0) === 127);
const positive = (value: string | null) => value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null;
const single = (query: URLSearchParams, key: string) => query.getAll(key).length === 1 ? query.get(key) : null;
function parsed(value?: string | null): URL | null {
    if (!value || bytes(value) > 1500 || !value.startsWith('/') || value.startsWith('//') || /[\\#]/.test(value) || unsafe(value, true)) return null;
    try {
        const target = new URL(value, ORIGIN);
        return target.origin === ORIGIN && target.pathname === value.split('?')[0] ? target : null;
    } catch { return null; }
}
function record(target: URL) {
    const match = target.pathname.match(/^\/health-safety\/ppe\/(inventory|allocations|inspections)\/([1-9]\d*)$/);
    return match && positive(match[2]) ? { kind: match[1] as PpeRecordKind, id: Number(match[2]) } : null;
}
function section(value: string | null, kind?: PpeRecordKind): PpeSection {
    const selected = PPE_SECTIONS.find((item) => item === value) ?? 'overview';
    if (kind === 'inventory' && selected === 'fit-training') return 'allocations';
    if (kind === 'allocations' && ['allocations', 'inspections'].includes(selected)) return 'overview';
    if (kind === 'inspections' && ['allocations', 'inspections', 'fit-training'].includes(selected)) return 'overview';
    return selected;
}
function registerQuery(target: URL) {
    const query = new URLSearchParams();
    const enums: Record<string, readonly string[]> = {
        tab: PPE_REGISTER_TABS,
        category: ['head', 'eye', 'ear', 'respiratory', 'hand', 'foot', 'high_visibility', 'body', 'fall_protection', 'other'],
        status: ['available', 'allocated', 'maintenance', 'condemned', 'disposed'],
        view: ['cards', 'table'],
    };
    for (const key of ['tab', 'site_id', 'category', 'status', 'ppe_type_id', 'search', 'page', 'allocations_page', 'view']) {
        const item = single(target.searchParams, key);
        if (!item || bytes(item) > 200 || unsafe(item)) continue;
        if (['site_id', 'ppe_type_id', 'page', 'allocations_page'].includes(key) && !positive(item)) continue;
        if (enums[key] && !enums[key].includes(item)) continue;
        query.set(key, item);
    }
    return query;
}
function containsPath(value: string, path: string): boolean {
    const target = new URL(value, ORIGIN);
    const back = target.searchParams.get('return_to');
    return target.pathname === path || (!!back && new URL(back, ORIGIN).pathname === path);
}
function clean(value: string | null | undefined, nested: boolean): string | null {
    const target = parsed(value);
    if (!target || (!nested && target.searchParams.has('return_to'))) return null;
    const own = record(target);
    const site = target.pathname.match(/^\/sites\/([1-9]\d*)$/);
    if (site && !positive(site[1])) return null;
    let base: string;
    if (target.pathname === PPE_REGISTER) {
        const query = registerQuery(target);
        base = target.pathname + (query.size ? `?${query}` : '');
    } else if (own) {
        const query = new URLSearchParams();
        const selected = single(target.searchParams, 'section');
        if (selected && PPE_SECTIONS.some((item) => item === selected)) query.set('section', section(selected, own.kind));
        for (const key of PPE_PAGE_KEYS) { const page = single(target.searchParams, key); if (positive(page)) query.set(key, page!); }
        base = target.pathname + (query.size ? `?${query}` : '');
    } else if (/^\/sites\/[1-9]\d*$/.test(target.pathname) && positive(target.pathname.split('/').at(-1) ?? null) && single(target.searchParams, 'tab') === 'ppe') {
        base = target.pathname + '?tab=ppe';
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = injuryReturn(leaf.pathname + leaf.search);
        if (base === INJURY_REGISTER && target.pathname !== INJURY_REGISTER) return null;
    }
    if (nested && (own || /^\/sites\/[1-9]\d*$/.test(target.pathname))) {
        const back = clean(single(target.searchParams, 'return_to'), false);
        if (back && !containsPath(back, target.pathname)) base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(back)}`;
    }
    return base;
}
/** A return hint carries read context only; the destination independently checks current access. */
export function ppeReturn(value?: string | null): string {
    return clean(value, true) ?? PPE_REGISTER;
}
export function ppeHref(kind: PpeRecordKind, id: number, options: { section?: PpeSection; command?: PpeCommand | null; attachment?: number | null; returnTo?: string; pages?: Partial<Record<PpePageKey, number>> } = {}): string {
    if (!Number.isSafeInteger(id) || id < 1) return PPE_REGISTER;
    const path = `${PPE_REGISTER}/${kind}/${id}`;
    const query = new URLSearchParams();
    if (options.section) query.set('section', section(options.section, kind));
    if (options.command && PPE_COMMANDS.some((item) => item === options.command)) query.set('action', options.command);
    if (options.attachment && Number.isSafeInteger(options.attachment) && options.attachment > 0) query.set('attachment', String(options.attachment));
    for (const key of PPE_PAGE_KEYS) { const page = options.pages?.[key]; if (page && Number.isSafeInteger(page) && page > 0) query.set(key, String(page)); }
    const back = clean(options.returnTo, true);
    if (back && !containsPath(back, path)) query.set('return_to', back);
    return path + (query.size ? `?${query}` : '');
}
export function ppeLocation(value: string) {
    const target = parsed(value);
    const own = target ? record(target) : null;
    const query = target?.searchParams ?? new URLSearchParams();
    const pages = Object.fromEntries(PPE_PAGE_KEYS.flatMap((key) => { const page = positive(single(query, key)); return page ? [[key, page]] : []; })) as Partial<Record<PpePageKey, number>>;
    return { kind: own?.kind ?? null, id: own?.id ?? null, pages,
        section: section(single(query, 'section'), own?.kind),
        command: PPE_COMMANDS.find((item) => item === single(query, 'action')) ?? null,
        attachment: positive(single(query, 'attachment')),
        returnTo: ppeReturn(single(query, 'return_to')) };
}
/** Old list bookmarks converge on the same canonical record, retaining the refined register. */
export function ppeLegacyHref(value: string): string | null {
    const target = parsed(value);
    if (!target || target.pathname !== PPE_REGISTER) return null;
    const item = positive(single(target.searchParams, 'item'));
    const allocation = positive(single(target.searchParams, 'allocation'));
    if ((target.searchParams.has('item') && target.searchParams.has('allocation')) || (!item && !allocation) || target.searchParams.getAll('item').length > 1 || target.searchParams.getAll('allocation').length > 1) return null;
    const kind = item ? 'inventory' : 'allocations';
    const context = ppeLocation(value);
    return ppeHref(kind, (item ?? allocation)!, { section: context.section, command: context.command, attachment: context.attachment, returnTo: ppeReturn(value) });
}
