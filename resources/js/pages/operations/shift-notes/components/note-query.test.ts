import { expect, it } from 'vitest';
import { noteExportUrl, type NoteFilters } from './note-query';
const filters: NoteFilters = {
    week: '2026-10-05',
    q: 'rāhui & 100%',
    type: 'note',
    client_id: 4,
    author_id: 7,
    site_id: 2,
    date_from: '2026-10-06',
    date_to: '2026-10-07',
    status: 'awaiting',
    flagged: true,
    page: 3,
};
it('exports the complete identical cohort, including privacy-safe literal search, without a page cap', () => {
    const url = new URL(noteExportUrl(filters), 'http://localhost');
    expect(url.pathname).toBe('/operations/shift-notes/export');
    expect(Object.fromEntries(url.searchParams)).toEqual({
        week: filters.week,
        q: filters.q,
        type: 'note',
        client_id: '4',
        author_id: '7',
        site_id: '2',
        date_from: filters.date_from,
        date_to: filters.date_to,
        status: 'awaiting',
        flagged: '1',
    });
});
it('omits unset optional filters rather than serializing null or false identities', () => {
    const url = new URL(
        noteExportUrl({
            ...filters,
            q: '',
            type: null,
            client_id: null,
            author_id: null,
            site_id: null,
            date_from: null,
            date_to: null,
            flagged: false,
            status: 'all',
        }),
        'http://localhost',
    );
    expect(Object.fromEntries(url.searchParams)).toEqual({
        week: filters.week,
        status: 'all',
    });
});
