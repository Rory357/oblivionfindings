import { expect, it } from 'vitest';
import { searchDashboardWorklists } from './dashboard-search';
import type { WorklistsPayload } from './worklists';
const worklists: WorklistsPayload = {
    attention: [{ key: 'awaiting_hs_acceptance', label: 'Awaiting acceptance', help: 'Named owner', count: 9,
        items: [{ id: 1, event_reference: 'HS-2026-0001', title: 'Bathroom fall', severity: 'high', reported_at: null, site: 'Kauri House', client: null, owner: 'Rory', action_url: '/health-safety/events/1?action=accept-handover' }] }],
    overdue_corrective_actions: [], open_investigations: [], notifiable_events: [],
    expiring: [{ type: 'ppe_repair', type_label: 'Secret equipment', label: 'Private serial ABC123', due_date: '2026-10-12', days_until: 0, register_url: '/health-safety/ppe/inventory/3', site: 'Kauri House' }],
    ppe_expiring_state: { source_integrity: 'repair_required', confirmed_count: null, total: null, repair_count: 1, physical_record_count: 1, displayed_count: 1, truncated: false },
};
it('matches all words against current preview facts while preserving exact actions and authoritative counts', () => {
    const result = searchDashboardWorklists(worklists, 'KAURI fall');
    expect(result.attention[0].items[0].action_url).toBe('/health-safety/events/1?action=accept-handover');
    expect(result.attention[0].count).toBe(9);
    expect(searchDashboardWorklists(worklists, 'another house').attention[0].items).toEqual([]);
    expect(worklists.attention[0].items).toHaveLength(1);
});
it('does not make damaged equipment facts searchable through a stale raw payload', () => {
    expect(searchDashboardWorklists(worklists, 'ABC123').expiring).toEqual([]);
    expect(searchDashboardWorklists(worklists, 'Secret').expiring).toEqual([]);
    expect(searchDashboardWorklists(worklists, 'equipment review').expiring).toHaveLength(1);
    expect(searchDashboardWorklists(worklists, 'equipment review').ppe_expiring_state).toBe(worklists.ppe_expiring_state);
});
it('removes restricted equipment from every search including an empty query', () => {
    const restricted: WorklistsPayload = { ...worklists, ppe_expiring_state: { ...worklists.ppe_expiring_state!, source_integrity: 'restricted' } };
    expect(searchDashboardWorklists(restricted, '').expiring).toEqual([]);
    expect(searchDashboardWorklists(restricted, 'Kauri').expiring).toEqual([]);
});
