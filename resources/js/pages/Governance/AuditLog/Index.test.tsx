import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: () => null,
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));

import { activityQuery, hasChangeDetails, type AuditEntry } from './Index';

const entry: AuditEntry = {
    key: 'action-1',
    kind: 'action',
    id: 1,
    type: 'resolution.voted',
    entity_type: 'Resolution',
    entity_id: 5,
    created_at: '2026-09-15T01:00:00+00:00',
    user: { id: 2, name: 'Jane Smith' },
    actor: 'Jane Smith',
    activity: 'voted on a resolution',
    sentence: 'Jane Smith voted on “Approve 2026/27 budget”',
    record_type: 'Resolution',
    record_title: 'Approve 2026/27 budget',
    record_url: '/governance/resolutions/5',
    can_see_details: true,
    details_withheld_reason: null,
    changes: [],
    details: [],
    description: null,
    ip_address: '203.0.113.5',
};

describe('audit log filters and details', () => {
    it('sends the activity filter to the right query key', () => {
        expect(activityQuery('action:resolution.voted')).toEqual({
            action: 'resolution.voted',
            change_type: null,
        });
        expect(activityQuery('change:updated')).toEqual({ action: null, change_type: 'updated' });
        expect(activityQuery('all')).toEqual({ action: null, change_type: null });
    });

    it('only offers "What changed" when there is something to show', () => {
        expect(hasChangeDetails(entry)).toBe(false);
        expect(
            hasChangeDetails({ ...entry, details: [{ label: 'Vote', value: 'For' }] }),
        ).toBe(true);
        expect(
            hasChangeDetails({
                ...entry,
                kind: 'change',
                changes: [{ label: 'Status', from: 'Draft', to: 'Open' }],
            }),
        ).toBe(true);
    });
});
