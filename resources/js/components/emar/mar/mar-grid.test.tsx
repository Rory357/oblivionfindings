import type { ScheduleRow } from '@/pages/meds/today/types';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MarGrid, { type MarGridMed } from './mar-grid';
const med: MarGridMed = {
    id: 1,
    name: 'Amlodipine',
    dosage: '5 mg',
    route: 'Oral',
    frequency: 'Daily',
    instructions: 'Morning',
    controlled_drug: false,
    high_risk: false,
    witness_required: false,
    is_inr: false,
    requires_observation: false,
    dose_times: ['08:00'],
};
describe('MAR second-person evidence', () => {
    it.each([
        ['pending', 'Second-person confirmation pending', 'warning'],
        ['disputed', 'Confirmation disputed', 'critical'],
        ['expired', 'Confirmation overdue', 'warning'],
    ] as const)(
        'keeps %s confirmation visible beside a Given outcome',
        (status, label, tone) => {
            const row = {
                key: '1-08:00',
                client_id: 10,
                client_name: 'Aroha',
                medication_id: 1,
                medication_name: 'Amlodipine',
                dose: '5 mg',
                route: 'Oral',
                is_controlled: false,
                requires_witness: false,
                scheduled_for: '2026-01-01T08:00:00+13:00',
                time: '08:00',
                round_label: 'Morning',
                status: 'given',
                mar_url: null,
                recorded: {
                    id: 2,
                    status: 'given',
                    administered_at: '2026-01-01T08:00:00+13:00',
                    time: '08:00',
                    by: 'Ana',
                    witness: null,
                    reason: null,
                    reason_label: null,
                    notes: null,
                    second_person_confirmation: {
                        id: 3,
                        status,
                        nominated_name: 'Mere',
                        due_at: '2026-01-01T08:30:00+13:00',
                    },
                },
            } satisfies ScheduleRow;
            render(
                <MarGrid
                    meds={[med]}
                    schedule={[row]}
                    canRecord={false}
                    canRecordControlled={false}
                    onRecord={vi.fn()}
                    onContext={vi.fn()}
                />,
            );
            const cell = screen.getByRole('button', {
                name: new RegExp(label),
            });
            expect(cell).toHaveTextContent('Given');
            expect(cell).toHaveTextContent(label);
            expect(cell.className).toContain('bg-status-' + tone + '-bg');
        },
    );
});
