import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import { TicketLinkedContext } from '../ticket-linked-context';

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
    },
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
    is_monitoring_evidence: false,
    can_unlink: true,
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

const incidentEvidenceFixture = {
    id: 30,
    version: 1,
    captured_at: '2026-07-18T00:56:00Z',
    checksum: 'a'.repeat(64),
    integrity: 'verified' as const,
    site: { id: 4, name: 'Kauri House' },
    alert: {
        id: 20,
        reference: 'CR-000123',
        type: 'device offline',
        severity: 'high',
        source: 'security_devices',
        triggered_at: '2026-07-18T00:55:00Z',
    },
    ticket: { id: 40, reference: 'IT-000040', title: 'Core switch offline' },
    device: {
        id: 10,
        uid: 'NET-CORE-01',
        name: 'Core switch before replacement',
        domain: 'it_infrastructure',
        category: 'network',
        subcategory: 'switch',
        status: 'offline',
        health_status: 'critical',
        last_seen_at: '2026-07-18T00:54:00Z',
    },
    observation: {
        id: 90,
        event_type: 'offline',
        severity: 'high',
        source: 'oblivion_monitoring',
        occurred_at: '2026-07-18T00:55:00Z',
        message: 'WAN probe failed.',
        monitor_correlation_key: 'b'.repeat(64),
    },
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
    workspace_access: { state: 'available' as const, message: null },
    ticket_href: '/it/tickets/42',
};

const changeFixture = {
    id: 9,
    reference: 'IT-000052',
    title: 'Replace gateway policy',
    workflow_state: 'scheduled',
    change_type: 'normal',
    risk_level: 'high',
    is_restricted: true,
    maintenance_starts_at: '2026-07-19T22:00:00Z',
    maintenance_ends_at: '2026-07-19T23:00:00Z',
    href: '/it/changes/9',
    workspace_access: { state: 'available' as const, message: null },
    ticket_href: '/it/tickets/52',
};

const majorIncidentFixture = {
    id: 12,
    reference: 'IT-000060',
    title: 'All-site identity outage',
    workflow_state: 'responding',
    severity: 'sev1',
    impact_summary: 'Authentication is unavailable across all active sites.',
    restored_at: null,
    next_update_due_at: '2026-07-19T22:30:00Z',
    href: '/it/major-incidents/12',
    workspace_access: { state: 'available' as const, message: null },
    ticket_href: '/it/tickets/60',
};

it('shows monitoring recovery and canonical deep links without raw payloads', () => {
    render(
        <TicketLinkedContext
            recoveredAt="2026-07-18T01:00:00Z"
            devices={[deviceFixture]}
            alerts={[alertFixture]}
            incidentEvidence={[incidentEvidenceFixture]}
            problems={[problemFixture]}
            changes={[changeFixture]}
            majorIncidents={[majorIncidentFixture]}
        />,
    );

    expect(screen.getByText('Monitoring recovered')).toBeInTheDocument();
    expect(
        screen.getByText('Frozen when the incident was raised'),
    ).toBeVisible();
    expect(screen.getByText('Integrity verified')).toBeVisible();
    expect(screen.getByText('Core switch before replacement')).toBeVisible();
    expect(screen.getByText('WAN probe failed.')).toBeVisible();
    expect(
        screen.getByText(/later live changes do not rewrite/i),
    ).toBeVisible();
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
    expect(screen.getByRole('link', { name: /IT-000052/i })).toHaveAttribute(
        'href',
        '/it/changes/9',
    );
    expect(screen.getByText('Scheduled maintenance')).toBeVisible();
    expect(screen.getByText('Major incident command')).toBeVisible();
    expect(screen.getByRole('link', { name: /IT-000060/i })).toHaveAttribute(
        'href',
        '/it/major-incidents/12',
    );
    expect(screen.queryByText(/signal_payload/i)).not.toBeInTheDocument();
    expect(
        screen.queryByText(/monitor_correlation_key/i),
    ).not.toBeInTheDocument();
});

it('keeps linked work context visible without offering agent workspaces to requesters', () => {
    const restrictedAccess = {
        state: 'restricted' as const,
        message: 'IT workspace access is required to open this record.',
    };

    render(
        <TicketLinkedContext
            recoveredAt={null}
            devices={[]}
            alerts={[]}
            problems={[
                {
                    ...problemFixture,
                    href: null,
                    workspace_access: restrictedAccess,
                },
            ]}
            changes={[
                {
                    ...changeFixture,
                    href: null,
                    workspace_access: restrictedAccess,
                },
            ]}
            majorIncidents={[
                {
                    ...majorIncidentFixture,
                    href: null,
                    workspace_access: restrictedAccess,
                },
            ]}
        />,
    );

    expect(screen.getByText(problemFixture.title)).toBeVisible();
    expect(screen.getByText(changeFixture.title)).toBeVisible();
    expect(screen.getByText(majorIncidentFixture.title)).toBeVisible();
    expect(
        screen.getAllByText(
            'IT workspace access is required to open this record.',
        ),
    ).toHaveLength(3);
    expect(screen.queryByRole('link', { name: /IT-000042/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /IT-000052/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /IT-000060/i })).toBeNull();
});

it('lets an agent remove a human Device link but protects monitoring evidence', async () => {
    const { router } = await import('@inertiajs/react');
    render(
        <TicketLinkedContext
            ticketId={40}
            canManage
            canLinkDevices
            deviceOptions={[
                {
                    id: 11,
                    name: 'Ward tablet',
                    uid: 'HC-0011',
                    site_id: 4,
                },
            ]}
            recoveredAt={null}
            devices={[
                deviceFixture,
                {
                    ...deviceFixture,
                    id: 12,
                    name: 'WAN monitor',
                    is_monitoring_evidence: true,
                    can_unlink: false,
                },
            ]}
            alerts={[]}
        />,
    );

    expect(screen.getByText('Linked context')).toBeVisible();
    expect(screen.getByText('Add affected Device')).toBeVisible();
    expect(screen.getByText('Monitoring evidence')).toBeVisible();
    expect(
        screen.getAllByRole('button', { name: /remove link/i }),
    ).toHaveLength(1);

    fireEvent.click(screen.getByRole('button', { name: /remove link/i }));
    expect(router.delete).toHaveBeenCalledWith(
        '/it/tickets/40/devices/10',
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('explains exact Security and Devices access instead of claiming every Device is linked', () => {
    render(
        <TicketLinkedContext
            ticketId={40}
            canManage
            canLinkDevices={false}
            deviceOptions={[]}
            recoveredAt={null}
            devices={[]}
            alerts={[]}
        />,
    );

    expect(screen.getByRole('note')).toHaveTextContent(
        'Security & Devices access is required to add affected Devices.',
    );
    expect(screen.queryByText('Add affected Device')).toBeNull();
    expect(
        screen.queryByText(
            'All available Devices for this Site are already linked.',
        ),
    ).toBeNull();
});
