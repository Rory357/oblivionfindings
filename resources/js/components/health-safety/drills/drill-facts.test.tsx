import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DrillChecks, DrillRunFacts } from './drill-facts';

const detail = {
    status: 'completed',
    started_at: '2026-10-08T00:00:00Z',
    completed_at: '2026-10-08T00:15:00Z',
    duration_minutes: 15,
    evacuation_time_seconds: 120,
    outcome: 'passed_actions',
    all_areas_checked: false,
    assembly_point_reached: true,
    roll_call_completed: true,
    total_participants: 8,
    residents_evacuated: 8,
    weather_conditions: null,
    improvements_identified: 'Improve the exit signage.',
    observer_notes: null,
};
describe('drill recorded facts', () => {
    it('never infers every resident was evacuated from matching mixed participant counts or completed roll-call', () => {
        render(
            <>
                <DrillRunFacts detail={detail} />
                <DrillChecks detail={detail} />
            </>,
        );
        expect(
            screen.queryByText('All residents evacuated', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/Residents evacuated — recorded count: 8/),
        ).toBeVisible();
        expect(
            screen.getByText(/These counts alone do not establish that every resident was safely evacuated\./),
        ).toBeVisible();
        expect(screen.getByText('Passed with actions')).toBeVisible();
        expect(
            screen.getByText('All areas checked · Not confirmed'),
        ).toBeVisible();
        expect(screen.getByText('Drill checks — 2 of 3 confirmed')).toBeVisible();
        expect(screen.getByText('Improve the exit signage.')).toBeVisible();
        expect(screen.getAllByText(/8 October 2026/)).toHaveLength(2);
    });
    it('keeps missing values and an unrecorded run distinct from zero or a failed check', () => {
        const unrecorded = {
            ...detail,
            status: 'scheduled',
            started_at: null,
            completed_at: null,
            duration_minutes: null,
            evacuation_time_seconds: null,
            total_participants: null,
            residents_evacuated: null,
            outcome: null,
        };
        render(
            <>
                <DrillRunFacts detail={unrecorded} />
                <DrillChecks detail={unrecorded} />
            </>,
        );
        expect(screen.queryByText(/Not confirmed/)).not.toBeInTheDocument();
        expect(screen.queryByText('0')).not.toBeInTheDocument();
        expect(screen.getByText('Drill checks — not recorded yet')).toBeVisible();
        expect(screen.getAllByText(/Not recorded/).length).toBeGreaterThan(5);
    });
});
