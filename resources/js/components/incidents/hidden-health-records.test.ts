import { describe, expect, it } from 'vitest';
import {
    HIDDEN_HEALTH_RECORD,
    HIDDEN_HEALTH_RECORD_ADDED,
    incidentHistoryItems,
    linkedRecordItems,
    type IncidentDetail,
} from './incident-detail-dialog';

// Approved drawing D3 (MASTER for Stephan, 30 Oct): a linked health record the viewer can't open says only that it
// exists — no title, kind or summary — in the linked list and in the one timeline.
const base = {
    occurred_at: null,
    submitted_at: null,
    reviewed_at: null,
    reopened_at: null,
    attachments: [],
    followups: [],
    ownership: null,
} as unknown as IncidentDetail;

describe('hidden linked health records', () => {
    const d = {
        ...base,
        hidden_health_records: [{ at: '2026-10-26T07:55:00Z' }],
    } as IncidentDetail;

    it('lists a hidden record with the approved words and nothing else', () => {
        const hidden = linkedRecordItems(d, 'Aroha').filter((item) =>
            String(item.key).startsWith('hidden-'),
        );
        expect(hidden).toHaveLength(1);
        expect(hidden[0].title).toBe(HIDDEN_HEALTH_RECORD);
        expect(hidden[0].sub).toBeUndefined();
        expect(hidden[0].href).toBeUndefined();
        expect(HIDDEN_HEALTH_RECORD).toBe(
            'A health record — hidden, you don’t have access',
        );
    });

    it('puts a timeline entry with no summary', () => {
        const entry = incidentHistoryItems(d).find(
            (item) => item.title === HIDDEN_HEALTH_RECORD_ADDED,
        );
        expect(entry).toBeDefined();
        expect(HIDDEN_HEALTH_RECORD_ADDED).toBe('Health record added — hidden');
        // Only the date, never a detail.
        expect(entry?.sub).not.toContain('·');
    });

    it('shows readable linked first aid and restraints on the same timeline', () => {
        const titles = incidentHistoryItems({
            ...base,
            first_aid_records: [
                {
                    id: 1,
                    reference: 'FA-1',
                    href: '#',
                    person: 'Aroha',
                    injury: 'bruise',
                    treatment_date: '2026-10-26T07:50:00Z',
                    ambulance_called: false,
                },
            ],
            restraint_events: [
                {
                    id: 2,
                    reference: 'RE-2',
                    restraint_type: 'physical',
                    severity: 'low',
                    within_support_plan: true,
                    injury_occurred: false,
                    started_at: '2026-10-26T07:40:00Z',
                },
            ],
        } as IncidentDetail).map((item) => item.title);
        expect(titles).toContain('First aid given');
        expect(titles).toContain('Restraint recorded');
    });
});
