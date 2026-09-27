import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Resource } from './dashboard';
import { AvailabilityDonut, BookingLoad } from './overview-panels';

describe('reviewed overview charts', () => {
    it('exposes every donut state and opens its records from a segment or legend', () => {
        const onSelect = vi.fn();
        const vehicles = [
            'Available now',
            'In use',
            'Restricted',
            'Unknown',
        ].map((availability, id) => ({ id, availability }) as Resource);
        render(<AvailabilityDonut vehicles={vehicles} onSelect={onSelect} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Unknown: 1 vehicle. Open records',
            }),
        );
        expect(onSelect).toHaveBeenLastCalledWith('Unknown');
        fireEvent.keyDown(
            screen.getByRole('button', {
                name: 'Chart: 1 Restricted vehicles. Open records',
            }),
            { key: 'Enter' },
        );
        expect(onSelect).toHaveBeenLastCalledWith('Restricted');
    });

    it('shows the scoped total and dates and links each day to its bookings', () => {
        const onDay = vi.fn();
        render(
            <BookingLoad
                hours={[
                    { date: '2026-09-27', site_id: 1, hours: 23 },
                    { date: '2026-09-28', site_id: 1, hours: 2 },
                    { date: '2026-09-27', site_id: 2, hours: 9 },
                ]}
                site="1"
                today="2026-09-27"
                healthy
                onDay={onDay}
            />,
        );
        expect(screen.getByText('25 hr')).toBeInTheDocument();
        expect(screen.getByText('27 Sep')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: '2026-09-27: 23 hr booked. View bookings.',
            }),
        );
        expect(onDay).toHaveBeenCalledWith('2026-09-27');
    });

    it('does not draw zero-hour bars for an unavailable feed', () => {
        render(
            <BookingLoad
                hours={[]}
                site="all"
                today="2026-09-27"
                healthy={false}
                onDay={vi.fn()}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('unavailable');
        expect(
            screen.queryByRole('group', { name: /Booked hours by day/ }),
        ).not.toBeInTheDocument();
    });
});
