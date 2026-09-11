import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    MonitoringIncidentEvidenceCard,
    type MonitoringIncidentEvidence,
} from './monitoring-incident-evidence-card';

const evidence: MonitoringIncidentEvidence = {
    id: 1,
    version: 2,
    captured_at: '2026-09-12T01:00:00Z',
    checksum: 'sealed',
    integrity: 'verified',
    site: { id: 1, name: 'Test Site' },
    alert: null,
    ticket: { id: 1, reference: 'IT-000001' },
    device: { id: 1, name: 'Test switch' },
    observation: {
        event_type: 'offline',
        occurred_at: '2026-09-12T01:00:00Z',
        message:
            'A confirmed monitoring fault requires technical verification.',
    },
};

describe('Monitoring incident evidence source', () => {
    it('presents direct technical evidence without inventing an alert or severity', () => {
        render(<MonitoringIncidentEvidenceCard evidence={evidence} />);
        expect(screen.getByText('Direct to IT')).toBeInTheDocument();
        expect(screen.getByText('Test switch')).toBeInTheDocument();
        expect(screen.getByText('Integrity verified')).toBeInTheDocument();
        expect(screen.queryByText('Original alert')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Control Room alert'),
        ).not.toBeInTheDocument();
    });

    it('retains the original operational alert and severity when present', () => {
        render(
            <MonitoringIncidentEvidenceCard
                evidence={{
                    ...evidence,
                    version: 1,
                    alert: {
                        id: 7,
                        reference: 'CR-000007',
                        type: 'device_offline',
                        severity: 'high',
                        triggered_at: evidence.captured_at,
                    },
                }}
            />,
        );
        expect(screen.getByText('Original alert')).toBeInTheDocument();
        expect(screen.getByText(/CR-000007/)).toBeInTheDocument();
        expect(screen.getByText('High')).toBeInTheDocument();
        expect(screen.queryByText('Direct to IT')).not.toBeInTheDocument();
    });
});
