import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { HsWorklists, type WorklistsPayload } from './components/worklists';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: React.AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { visit: vi.fn() },
    usePage: () => ({
        props: { auth: { can: { governance: { view: false } } } },
    }),
}));

const worklists: WorklistsPayload = {
    attention: [
        {
            key: 'awaiting_hs_acceptance',
            label: 'Awaiting H&S acceptance',
            help: 'A named H&S owner must accept governance responsibility.',
            count: 1,
            items: [
                {
                    id: 80,
                    event_reference: 'HS-2026-0080',
                    title: 'Bathroom safety incident',
                    severity: 'high',
                    reported_at: '2026-07-16T08:00:00+12:00',
                    site: 'Maple House',
                    client: 'Aroha Rangi',
                    owner: 'H&S Owner',
                    action_url:
                        '/health-safety/events/80?action=accept-handover',
                },
            ],
        },
    ],
    overdue_corrective_actions: [
        {
            id: 10,
            reference: 'CA-2026-9010',
            title: 'Install a safety rail',
            priority: 'high',
            status: 'in_progress',
            due_date: '2026-07-21',
            days_overdue: 2,
            owner: 'Site Manager',
            client_id: null,
            staff_id: null,
            event_reference: 'HS-2026-0080',
        },
    ],
    open_investigations: [],
    notifiable_events: [],
    expiring: [],
};

describe('Health & Safety dashboard attention worklists', () => {
    it('renders awaiting acceptance first and links the row directly to the acceptance action', () => {
        render(
            <HsWorklists
                worklists={worklists}
                show={['acceptance', 'corrective_actions']}
            />,
        );

        const acceptance = screen.getByText('Awaiting H&S acceptance');
        const corrective = screen.getByText('Overdue corrective actions');
        expect(
            acceptance.compareDocumentPosition(corrective) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(
            screen.getByText(
                'A named H&S owner must accept governance responsibility.',
            ),
        ).toBeVisible();
        expect(
            screen.getByLabelText('1 H&S handover awaiting acceptance'),
        ).toHaveTextContent('1');

        const directLink = screen.getByRole('link', {
            name: 'Accept H&S handover for HS-2026-0080',
        });
        expect(directLink).toHaveAttribute(
            'href',
            '/health-safety/events/80?action=accept-handover',
        );
        fireEvent.focus(directLink);
        expect(directLink).toHaveClass('focus-visible:ring-2');
    });
});
