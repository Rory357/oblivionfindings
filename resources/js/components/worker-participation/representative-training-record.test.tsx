import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { RepresentativeDetailDialog } from './representative-detail-dialog';
import type { RepDetail } from './shared';

// Keep the actual detail, wizard chrome and training pane; do not submit commands.
vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: {},
        processing: false,
        put: vi.fn(),
        setData: vi.fn(),
        setError: vi.fn(),
    }),
}));
afterEach(cleanup);

function show(days: number, initialDate: string | null = null) {
    const record: RepDetail = {
        id: 31,
        user: { id: 7, name: 'Recorded representative' },
        site: { id: 4, name: 'North House' },
        work_group: null,
        election_method: 'elected',
        elected_at: '2026-01-01',
        status: 'active',
        training_days_completed: days,
        initial_training_completed_at: initialDate,
        term_expires_at: null,
        notes: null,
    };
    render(
        <RepresentativeDetailDialog
            detail={record}
            open
            onClose={vi.fn()}
            staff={[]}
            sites={[]}
            can={{ manage: true }}
            initialAction={null}
        />,
    );
}

describe('Representative training evidence presentation', () => {
    it('does not turn two cumulative days into annual completion or a qualification', () => {
        show(2);
        expect(
            screen.getByText('Recorded days (cumulative)'),
        ).toBeInTheDocument();
        expect(screen.getByText('2 days', { exact: true })).not.toHaveClass(
            'text-status-success',
        );
        expect(
            screen.getByText('Not confirmed by this cumulative total'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Minimum met|Standard met|HSWA minimum/),
        ).not.toBeInTheDocument();
    });
    it('keeps a low recorded total actionable without inventing a legal minimum', () => {
        show(0);
        expect(
            screen.getByText('Fewer than two training days recorded.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Below the training minimum.'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record training',
            }),
        );
        expect(
            screen.getByText('Reported initial training date'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/This cumulative total does not establish/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save training' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/adds a tracked credential/),
        ).not.toBeInTheDocument();
    });
    it('does not replace an unrecorded total with zero days', () => {
        show(Number.NaN);
        expect(
            screen.getByText('Not recorded', { exact: true }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('0 days', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Fewer than two training days recorded.'),
        ).not.toBeInTheDocument();
    });
    it('labels the reported date separately and preserves its literal calendar day', () => {
        show(4, '2026-10-10');
        const label = screen.getByText('Reported initial training date');
        expect(
            within(label.parentElement!).getByText('10 Oct 2026'),
        ).toBeInTheDocument();
        expect(screen.queryByText('First trained')).not.toBeInTheDocument();
    });
});
