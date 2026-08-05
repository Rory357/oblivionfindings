import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { JourneyGateList, type JourneyGateData } from './journey-gate-list';

describe('JourneyGateList', () => {
    it('renders the server-owned requirement labels and direct action links', () => {
        const gate: JourneyGateData = {
            allowed: false,
            requirements: [
                {
                    key: 'incident_review',
                    complete: true,
                    label: 'Incident review complete',
                    href: '/incidents/42',
                },
                {
                    key: 'health_safety_governance',
                    complete: false,
                    label: 'Close linked H&S governance HS-2026-0017',
                    href: '/health-safety/events/17',
                },
            ],
        };

        render(<JourneyGateList gate={gate} />);

        expect(
            screen.getByText('Incident review complete'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Close linked H&S governance HS-2026-0017',
            }),
        ).toHaveAttribute('href', '/health-safety/events/17');
        expect(screen.getByText('Complete')).toBeInTheDocument();
        expect(screen.getByText('Required')).toBeInTheDocument();
    });

    it('shows a truthful ready state only when the server gate allows the transition', () => {
        render(
            <JourneyGateList
                gate={{
                    allowed: true,
                    requirements: [
                        {
                            key: 'operational_tasks',
                            complete: true,
                            label: 'All operational tasks have a final outcome',
                            href: '/control-room/alerts/11',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Ready to continue')).toBeInTheDocument();
        expect(screen.queryByText('Required')).not.toBeInTheDocument();
    });

    it('shows an unmet requirement as guidance when the viewer cannot open its module', () => {
        render(
            <JourneyGateList
                gate={{
                    allowed: false,
                    requirements: [
                        {
                            key: 'health_safety_governance',
                            complete: false,
                            label: 'Ask an H&S manager to close linked governance',
                            href: null,
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByText('Ask an H&S manager to close linked governance'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText('Required')).toBeInTheDocument();
    });
});
