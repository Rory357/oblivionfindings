import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import {
    LinkedOperationalEvidence,
    type LinkedOperationalEvidenceData,
} from './linked-operational-evidence';

const evidence: LinkedOperationalEvidenceData = {
    label: 'Linked Control Room evidence',
    read_only: true,
    source: {
        id: 11,
        reference: 'CR-2026-2401',
        alert_type: 'fall_detected',
        severity: 'high',
        status: 'triaging',
        href: null,
        site: { id: 3, name: 'Kauri House' },
        client: { id: 8, name: 'Mereana Ropata' },
        triggered_at: '2026-07-16T08:00:00Z',
        created_at: '2026-07-16T08:01:00Z',
        updated_at: '2026-07-16T09:00:00Z',
    },
    notes: [
        {
            id: 21,
            type: 'action',
            purpose: 'immediate_controls',
            purpose_label: 'Immediate controls',
            content: 'Loading bay isolated and first aid started.',
            author: { id: 4, name: 'Ari Patel' },
            created_at: '2026-07-16T08:05:00Z',
        },
    ],
    tasks: [
        {
            id: 31,
            title: 'Call the on-call nurse',
            description: null,
            status: 'in_progress',
            priority: 'high',
            owner: { id: 4, name: 'Ari Patel' },
            due_at: '2026-07-16T09:30:00Z',
            overdue: false,
            transfer: {
                state: 'open',
                corrective_action_reference: null,
                transferred_at: null,
            },
        },
        {
            id: 32,
            title: 'Retain the loading-bay recording',
            description: null,
            status: 'transferred',
            priority: 'critical',
            owner: null,
            due_at: null,
            overdue: false,
            transfer: {
                state: 'transferred',
                corrective_action_reference: 'CA-2026-0240',
                transferred_at: '2026-07-16T08:30:00Z',
            },
        },
    ],
    evidence_packs: [
        {
            id: 41,
            title: 'Loading-bay evidence',
            status: 'complete',
            item_count: 1,
            items: [
                {
                    id: 42,
                    type: 'document',
                    title: 'Scene preservation record',
                    description: 'The duty manager retained the recording.',
                    mime_type: 'text/plain',
                    file_size: 12,
                    captured_at: '2026-07-16T08:20:00Z',
                    captured_by: { id: 4, name: 'Ari Patel' },
                    download_url:
                        '/incidents/42/control-room-evidence/42/download',
                },
            ],
        },
    ],
    communications: [
        {
            id: 51,
            channel: 'phone_call',
            direction: 'outbound',
            purpose: 'handover',
            subject: 'Duty manager update',
            content: 'Duty manager confirmed the immediate controls.',
            status: 'sent',
            sent_at: '2026-07-16T08:25:00Z',
            delivered_at: null,
            created_at: '2026-07-16T08:24:00Z',
        },
    ],
};

afterEach(cleanup);

describe('LinkedOperationalEvidence', () => {
    it('renders canonical read-only notes, tasks, files, communications, and source context', () => {
        render(<LinkedOperationalEvidence evidence={evidence} />);

        expect(
            screen.getByRole('heading', {
                name: 'Linked Control Room evidence',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Read-only operational record'),
        ).toBeInTheDocument();
        expect(screen.getByText('CR-2026-2401')).toBeInTheDocument();
        expect(screen.getByText('Kauri House')).toBeInTheDocument();
        expect(screen.getByText('Mereana Ropata')).toBeInTheDocument();
        expect(screen.getByText('Fall Detected')).toBeInTheDocument();
        expect(screen.getByText('Triaging · High')).toBeInTheDocument();
        expect(screen.getByText('Immediate controls')).toBeInTheDocument();
        expect(
            screen.getByText('Loading bay isolated and first aid started.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Call the on-call nurse')).toBeInTheDocument();
        expect(screen.getByText('Ari Patel · due')).toBeInTheDocument();
        expect(
            screen.getByText('Retain the loading-bay recording'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Transferred to CA-2026-0240'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Scene preservation record'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Download Scene preservation record',
            }),
        ).toHaveAttribute(
            'href',
            '/incidents/42/control-room-evidence/42/download',
        );
        expect(screen.getByText('Duty manager update')).toBeInTheDocument();
        expect(
            screen.getByText('Outbound · Phone Call · Sent'),
        ).toBeInTheDocument();
    });

    it('explains an empty linked record without implying official incident evidence is missing', () => {
        render(
            <LinkedOperationalEvidence
                evidence={{
                    ...evidence,
                    notes: [],
                    tasks: [],
                    evidence_packs: [],
                    communications: [],
                }}
            />,
        );

        expect(
            screen.getByText(
                'No operator notes, operational tasks, evidence packs, or communications were recorded for this alert.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('No incident attachments were recorded.'),
        ).not.toBeInTheDocument();
    });
});
