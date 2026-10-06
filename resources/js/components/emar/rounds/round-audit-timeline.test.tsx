import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RoundAuditTimeline, {
    itemsToAuditEntries,
} from './round-audit-timeline';
import type { RoundItem } from './types';
describe('round confirmation audit', () => {
    it.each([
        ['pending', 'Second-person confirmation pending'],
        ['disputed', 'Confirmation disputed'],
        ['expired', 'Confirmation overdue'],
    ] as const)(
        'retains %s evidence when flattening a guided round',
        (status, label) => {
            const item = {
                medication_name: 'Amlodipine',
                dose: '5 mg',
                client_name: 'Aroha',
                administration: {
                    status: 'given',
                    administered_by: 'Ana',
                    administered_at: '2026-01-01T19:00:00Z',
                    witnessed_by: null,
                    blood_glucose_level: null,
                    pulse_bpm: null,
                    reason: null,
                    second_person_confirmation: {
                        id: 3,
                        status,
                        nominated_name: 'Mere',
                        due_at: '2026-01-01T19:30:00Z',
                    },
                },
            } as RoundItem;
            render(
                <RoundAuditTimeline
                    meta={{}}
                    entries={itemsToAuditEntries([item])}
                />,
            );
            expect(screen.getByText(new RegExp(label))).toBeInTheDocument();
            expect(
                screen.getByText(/Aroha · Ana · 08:00 am/),
            ).toBeInTheDocument();
            expect(
                screen.queryByText(/Witness PIN verified/),
            ).not.toBeInTheDocument();
        },
    );
});
