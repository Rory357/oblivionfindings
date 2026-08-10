import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TopologyMap } from './topology-map';

describe('TopologyMap', () => {
    it('provides canonical links and a keyboard-readable evidence fallback', () => {
        render(
            <TopologyMap
                topology={{
                    source: 'native_runtime',
                    nodes: [
                        {
                            id: 1,
                            name: 'WAN gateway',
                            site: 'Central Site',
                            href: '/security-devices/devices/1',
                            health: 'critical',
                        },
                        {
                            id: 2,
                            name: 'Access switch',
                            site: 'Central Site',
                            href: '/security-devices/devices/2',
                            health: 'warning',
                        },
                    ],
                    edges: [
                        {
                            id: 'runtime-9',
                            parentId: 1,
                            parentName: 'WAN gateway',
                            childId: 2,
                            childName: 'Access switch',
                            label: 'LLDP neighbour',
                            port: 'eth0 → uplink',
                            source: 'snmp',
                            confidence: 0.94,
                            reviewState: 'inferred',
                            evidenceLabel: 'SNMP LLDP evidence',
                        },
                    ],
                    snapshots: [
                        {
                            id: 3,
                            site: {
                                id: 4,
                                name: 'Central Site',
                                href: '/security-devices/sites/4',
                            },
                            source: 'snmp',
                            capturedAt: '2026-07-23T08:00:00Z',
                            nodeCount: 2,
                            edgeCount: 1,
                            changeCount: 1,
                        },
                    ],
                    changes: { added: 1, removed: 0, changed: 0 },
                }}
            />,
        );

        expect(
            screen.getByRole('link', { name: /WAN gateway/i }),
        ).toHaveAttribute('href', '/security-devices/devices/1');
        const relationship = screen.getByRole('article');
        expect(relationship).toHaveAttribute('tabindex', '0');
        expect(
            screen.getByText(/SNMP LLDP evidence · 94% confidence/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/SNMP · 2 nodes · 1 edges · 1 changes/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Central Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
        expect(
            screen.getByText(
                'Since the previous snapshots: 1 added · 0 removed · 0 changed',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Inferred')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /unlock|restart|wipe|run command/i,
            }),
        ).not.toBeInTheDocument();
    });
});
