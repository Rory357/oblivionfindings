import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import { TicketLinkedContext } from '../ticket-linked-context';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const deviceFixture = {
    id: 10,
    uid: 'NET-CORE-01',
    name: 'Core switch',
    domain: 'it_infrastructure',
    category: 'network',
    status: 'active',
    health_status: 'healthy',
    last_seen_at: '2026-07-18T00:58:00Z',
    href: '/security-devices/devices/10',
};

const alertFixture = {
    id: 20,
    reference: 'CR-000123',
    alert_type: 'Device Offline',
    severity: 'high',
    status: 'resolved',
    triggered_at: '2026-07-18T00:55:00Z',
    href: '/control-room/alerts/20',
};

const problemFixture = {
    id: 7,
    reference: 'IT-000042',
    title: 'Repeated VPN authentication failures',
    workflow_state: 'known_error',
    root_cause: 'A gateway node has an outdated certificate chain.',
    workaround: 'Reconnect through the secondary gateway.',
    known_error_at: '2026-07-18T00:59:00Z',
    href: '/it/problems/7',
    ticket_href: '/it/tickets/42',
};

it('shows monitoring recovery and canonical deep links without raw payloads', () => {
    render(
        <TicketLinkedContext
            recoveredAt="2026-07-18T01:00:00Z"
            devices={[deviceFixture]}
            alerts={[alertFixture]}
            problems={[problemFixture]}
        />,
    );

    expect(screen.getByText('Monitoring recovered')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /core switch/i })).toHaveAttribute(
        'href',
        '/security-devices/devices/10',
    );
    expect(screen.getByRole('link', { name: /cr-000123/i })).toHaveAttribute(
        'href',
        '/control-room/alerts/20',
    );
    expect(screen.getByText('Healthy')).toBeInTheDocument();
    expect(screen.getByText('Resolved')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /IT-000042/i })).toHaveAttribute(
        'href',
        '/it/problems/7',
    );
    expect(
        screen.getByText(/Reconnect through the secondary gateway/i),
    ).toBeVisible();
    expect(screen.queryByText(/signal_payload/i)).not.toBeInTheDocument();
});
