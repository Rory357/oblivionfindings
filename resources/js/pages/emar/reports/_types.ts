export type Row = Record<string, string | number | boolean | null | Record<string, unknown>>;
export type Person = { id: number; name: string };
export type Filters = { view: 'standard' | 'audit' | 'exports'; report: string; sub: string; period: string; date_from: string; date_to: string; site_id: number | null; client_id: number | null; kind: string; q: string };
export type ExportOption = { type: string; label: string; format: string; description: string; allowed: boolean };
export type Props = {
    filters: Filters; reports: Record<string, string>; sites: Person[]; people: Person[];
    data: { totals: Record<string, number | null>; notice: string | null }; page: { data: Row[]; total: number; last_page: number; links: { url: string | null; label: string; active: boolean }[] } | null;
    locked: string | null; finance: boolean; can: { audit: boolean; controlled: boolean; verify: boolean };
    exports: ExportOption[]; purposes: Record<string, string>; as_at: string;
};
export type ExportContext = Pick<Props, 'filters' | 'sites' | 'people' | 'finance' | 'purposes' | 'exports'>;

export async function requestJson(url: string, payload?: Record<string, unknown>) {
    const response = await reportRequest(url, payload);
    return response.json();
}

export async function reportRequest(url: string, payload?: Record<string, unknown>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
    const response = await fetch(url, {
        method: payload ? 'POST' : 'GET', credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(payload ? { 'Content-Type': 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) } : {}) } : {}) },
        ...(payload ? { body: JSON.stringify(payload) } : {}),
    });
    if (!response.ok) {
        const body = await response.json().catch(() => null);
        const errors = body?.errors as Record<string, string[]> | undefined;
        throw new Error(errors ? Object.values(errors).flat().join(' ') : body?.message ?? 'This request could not be completed. Your selections have been kept.');
    }
    return response;
}
