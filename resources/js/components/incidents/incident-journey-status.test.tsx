import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AlertStatus } from '../control-room/alert-worklist/alert-status';
import { IncidentJourneyStatus } from './incident-journey-status';

describe('IncidentJourneyStatus', () => {
    it('shows all three lifecycle stages with official references, text states and NZ time', () => {
        render(
            <IncidentJourneyStatus
                occurredAt="2026-07-15T08:25:00Z"
                stages={[
                    {
                        key: 'control_room',
                        label: 'Control Room',
                        referenceNumber: 'CR-2026-1204',
                        state: 'in_progress',
                        href: '/control-room/alerts/12',
                    },
                    {
                        key: 'incident',
                        label: 'Incident report',
                        referenceNumber: 'INC-2026-0831',
                        state: 'complete',
                        href: '/incidents?incident=83',
                    },
                    {
                        key: 'health_safety',
                        label: 'Health & Safety',
                        referenceNumber: 'HS-2026-0440',
                        state: 'waiting',
                        href: '/health-safety/events/44',
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('list', { name: 'Incident journey' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /CR-2026-1204/ }),
        ).toHaveAttribute('href', '/control-room/alerts/12');
        expect(screen.getByText('In progress')).toBeInTheDocument();
        expect(screen.getByText('Complete')).toBeInTheDocument();
        expect(screen.getByText('Waiting for acceptance')).toBeInTheDocument();
        expect(screen.getByText(/Wed 15 Jul, 8:25 pm/)).toBeInTheDocument();
    });

    it('pairs alert colour with an icon and plain-language status text', () => {
        render(
            <AlertStatus
                status="confirmed"
                severity="critical"
                slaStatus="breached"
            />,
        );

        const status = screen.getByRole('status');
        expect(status).toHaveTextContent('Confirmed incident');
        expect(status).toHaveTextContent('Critical severity');
        expect(status).toHaveTextContent('SLA breached');
        expect(status.querySelectorAll('svg')).toHaveLength(3);
    });
});
