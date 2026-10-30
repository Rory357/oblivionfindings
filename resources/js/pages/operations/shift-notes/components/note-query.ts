import type { StatusTab } from './shared';

export type NoteFilters = {
    week: string;
    q: string;
    type: string | null;
    client_id: number | null;
    author_id: number | null;
    site_id: number | null;
    date_from: string | null;
    date_to: string | null;
    flagged: boolean;
    status: StatusTab;
    page: number;
};
export type NoteSummary = {
    total: number;
    flagged: number;
    awaiting: number;
    reviewed: number;
};
export type NotePagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
export type NoteEvidence = {
    state: 'no_records' | 'recorded';
    timezone: string;
    checked_at: string;
    period_start: string;
    period_end_exclusive: string;
    scope: 'permitted_sites' | 'own_related_records_in_permitted_sites';
    complete: boolean;
};
export function noteExportUrl(filters: NoteFilters) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (key !== 'page' && value !== null && value !== '' && value !== false)
            query.set(key, value === true ? '1' : String(value));
    }
    return `/operations/shift-notes/export?${query}`;
}
