import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { MyTicketsList } from './my-tickets-list';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('MyTicketsList', () => {
    it('uses a focusable native link for each ticket destination', () => {
        render(
            <MyTicketsList
                tickets={[
                    {
                        id: 35,
                        reference: 'IT-000035',
                        title: 'New ticket needs attention',
                        description: 'A keyboard navigation regression.',
                        category: 'access',
                        priority: 'high',
                        status: 'open',
                        waiting_party: null,
                        assignee: null,
                        age: 'Just raised',
                        resolved: null,
                        can_rate: false,
                        csat_score: null,
                    },
                ]}
            />,
        );

        const ticket = screen.getByRole('link', {
            name: /new ticket needs attention.*it-000035/i,
        });

        expect(ticket).toHaveAttribute('href', '/it/tickets/35');
        expect(ticket).toHaveProperty('tabIndex', 0);

        ticket.focus();
        expect(ticket).toHaveFocus();

        // Native links activate on Enter; this confirms the focused target is
        // the ticket's direct, browser-navigable destination.
        fireEvent.keyDown(ticket, { key: 'Enter' });
        expect(ticket).toHaveAttribute('href', '/it/tickets/35');
    });
});
