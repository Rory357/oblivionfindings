import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TicketRoutingSummary } from '../ticket-routing-summary';

const routing = {
    queue: { id: 11, name: 'Clinical technology' },
    team: { id: 12, name: 'Infrastructure' },
    owner: { id: 13, name: 'Alex Morgan' },
};

describe('TicketRoutingSummary', () => {
    it('makes the routed queue team and accountable owner explicit', () => {
        render(<TicketRoutingSummary routing={routing} />);

        expect(screen.getByText('Clinical technology')).toBeInTheDocument();
        expect(screen.getByText('Infrastructure')).toBeInTheDocument();
        expect(screen.getByText('Alex Morgan')).toBeInTheDocument();
        expect(screen.getByText('Accountable owner')).toBeInTheDocument();
    });

    it('keeps the compact queue row understandable', () => {
        render(<TicketRoutingSummary routing={routing} compact />);

        expect(screen.getByText('Clinical technology')).toBeInTheDocument();
        expect(
            screen.getByText('Infrastructure · Owner: Alex Morgan'),
        ).toBeInTheDocument();
    });

    it('states when no routing rule matched', () => {
        render(
            <TicketRoutingSummary
                routing={{ queue: null, team: null, owner: null }}
            />,
        );

        expect(screen.getByText('Queue not configured')).toBeInTheDocument();
        expect(screen.getByText('Team not configured')).toBeInTheDocument();
        expect(screen.getByText('Owner not assigned')).toBeInTheDocument();
    });
});
