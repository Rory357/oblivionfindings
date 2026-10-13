import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DoseMeters } from './dose-meters';

const doses = {
    scheduled: 3,
    due: 1,
    due_until: '2026-10-03T10:15:00+13:00',
    late: 1,
    oldest_late: '2026-10-03T08:00:00+13:00',
    recorded: 1,
    so_far: 2,
};

describe('record header dose meters (EA-110)', () => {
    it('shows due, late and recorded-today figures with their captions', () => {
        render(
            <DoseMeters doses={doses} onChart={vi.fn()} onHistory={vi.fn()} />,
        );
        expect(screen.getByText('Until 10:15 am')).toBeInTheDocument();
        expect(screen.getByText('Oldest due 8:00 am')).toBeInTheDocument();
        expect(screen.getByText('1/2')).toBeInTheDocument();
        expect(screen.getByText('of doses due so far')).toBeInTheDocument();
    });

    it('says n/a when nothing is on the chart today, never zero', () => {
        render(
            <DoseMeters
                doses={{
                    ...doses,
                    scheduled: 0,
                    due: 0,
                    late: 0,
                    recorded: 0,
                    so_far: 0,
                    due_until: null,
                    oldest_late: null,
                }}
                onChart={vi.fn()}
                onHistory={vi.fn()}
            />,
        );
        expect(screen.getAllByText('n/a')).toHaveLength(3);
        expect(screen.getByText('No doses due yet')).toBeInTheDocument();
    });

    it('says the day is unavailable rather than showing zeros', () => {
        render(
            <DoseMeters doses={null} onChart={vi.fn()} onHistory={vi.fn()} />,
        );
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
        expect(
            screen.getByText('Today’s dose history can’t be read'),
        ).toBeInTheDocument();
        expect(screen.queryByText('0')).not.toBeInTheDocument();
    });
});
