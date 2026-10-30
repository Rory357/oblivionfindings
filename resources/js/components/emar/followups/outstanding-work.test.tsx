import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { OutstandingMedicationWork } from './outstanding-work';
import type { LegacyEffectCheck, OutstandingMedicationWorkData } from './types';

afterEach(cleanup);
const oldCheck: LegacyEffectCheck = {
    source_key: 'effect:18',
    administration_id: 18,
    client: { id: 2, name: 'Test person' },
    site: { id: 3, name: 'Test house' },
    medication: { id: 4, name: 'Test medicine' },
    owner: null,
    due_at: null,
    given_at: '2026-10-02T08:00:00+13:00',
    can_prepare: false,
    record_url: '/emar/mar?client_id=2',
    url: '/emar/followups?client_id=2&administration=18',
};
const work: OutstandingMedicationWorkData = {
    followups: [],
    legacy_effect_checks: {
        total: 26,
        overdue: 0,
        unscheduled: 26,
        filtered_total: 26,
        data: [oldCheck],
        has_more: true,
    },
    followup_counts: { open: 26, effect: 26, overdue: 0, unscheduled: 26 },
};

it('shows older-only work and full counts without inventing a check time or owner', () => {
    render(<OutstandingMedicationWork work={work} clientId={2} />);
    expect(screen.getByText('26 with no check time')).toBeVisible();
    expect(screen.getByText(/Check time not set — arrange/)).toBeVisible();
    expect(screen.getByText(/Owner: Not assigned/)).toBeVisible();
    expect(screen.getByText(/Showing 1 of 26/)).toBeVisible();
    expect(
        screen.getByRole('link', { name: /Effect check · Test medicine/ }),
    ).toHaveAttribute('href', oldCheck.url);
});

it('preserves a handover draft by opening linked work in a separate tab with truthful acknowledgement copy', () => {
    render(<OutstandingMedicationWork work={work} handover />);
    expect(
        screen.getByText(/Acknowledgement alone does not complete/),
    ).toBeVisible();
    for (const link of screen.getAllByRole('link')) {
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    }
    expect(
        screen.getByRole('link', { name: /Open follow-ups/ }),
    ).toHaveAttribute('href', '/emar/followups?client_id=2');
});

it('removes completed work when a refreshed projection contains no outstanding checks', () => {
    const view = render(<OutstandingMedicationWork work={work} />);
    view.rerender(
        <OutstandingMedicationWork
            work={{
                followups: [],
                followup_counts: {
                    open: 0,
                    effect: 0,
                    overdue: 0,
                    unscheduled: 0,
                },
            }}
        />,
    );
    expect(
        screen.queryByRole('region', { name: 'Current medication follow-ups' }),
    ).not.toBeInTheDocument();
});
