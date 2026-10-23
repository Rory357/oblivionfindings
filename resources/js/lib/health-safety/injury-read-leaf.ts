/** Literal injury destinations shared by source returns without importing their navigation chain. */
export const INJURY_READ_SECTIONS = ['overview', 'recovery', 'capacity', 'scheduling', 'treatment', 'connections', 'evidence', 'history'] as const;
const positive = (value: string | null) => !!value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value));
const single = (query: URLSearchParams, key: string) => query.getAll(key).length === 1 ? query.get(key) : null;
const bytes = (value: string) => new TextEncoder().encode(value).length;
const date = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value + 'T00:00:00Z')) && new Date(value + 'T00:00:00Z').toISOString().slice(0, 10) === value;
const enums: Record<string, readonly string[]> = {
    tab: ['all', 'reported', 'under_treatment', 'return_to_work', 'recovered', 'closed', 'worksafe', 'acc'],
    status: ['reported', 'under_treatment', 'return_to_work', 'recovered', 'closed'],
    severity: ['minor', 'moderate', 'serious', 'critical'],
    treatment: ['none', 'first_aid', 'gp_visit', 'hospital', 'emergency_department', 'hospitalisation', 'specialist', 'ongoing'],
    period: ['all', 'week', '30d', 'quarter', 'year'], acc_open: ['0', '1', 'false', 'true'], worksafe: ['0', '1', 'false', 'true'],
};
export function injuryReadLeaf(value: string | null | undefined): string | null {
    if (!value || bytes(value) > 1500 || !value.startsWith('/') || value.startsWith('//') || /[\\#\u0000-\u0020\u007f]/.test(value)) return null;
    let target: URL; try { target = new URL(value, 'https://injury-leaf.invalid'); } catch { return null; }
    if (target.origin !== 'https://injury-leaf.invalid' || target.pathname !== value.split('?')[0] || target.searchParams.has('return_to')) return null;
    const record = target.pathname.match(/^\/health-safety\/injuries\/([1-9]\d*)$/);
    if (target.pathname !== '/health-safety/injuries' && (!record || !positive(record[1]))) return null;
    const query = new URLSearchParams();
    if (record) {
        const section = single(target.searchParams, 'section');
        if (section && (section === 'rtw' || INJURY_READ_SECTIONS.some((item) => item === section))) query.set('section', section === 'rtw' ? 'recovery' : section);
        for (const key of ['plan', 'assessment', 'duty']) { const value = single(target.searchParams, key); if (positive(value)) query.set(key, value!); }
    } else {
        for (const key of ['tab', 'q', 'site_id', 'severity', 'status', 'treatment', 'acc_open', 'worksafe', 'period', 'from', 'to', 'type', 'body_part', 'page']) {
            const value = single(target.searchParams, key);
            if (!value || bytes(value) > 200 || /[\u0000-\u001f\u007f]/.test(value)) continue;
            if (['site_id', 'page'].includes(key) && !positive(value)) continue;
            if (['from', 'to'].includes(key) && !date(value)) continue;
            if (enums[key] && !enums[key].includes(value)) continue;
            query.set(key, value);
        }
    }
    return target.pathname + (query.size ? `?${query}` : '');
}
