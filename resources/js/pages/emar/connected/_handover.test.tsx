import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { HandoverFacts } from './_handover';
import type { Transfer } from './_types';
const transfer: Transfer = {
    id: 1,
    direction: 'outgoing',
    client_id: 7,
    client_name: 'Fictional person',
    provider_name: 'Fictional provider',
    recipient_name: 'Fictional receiver',
    purpose: 'Acceptance',
    disclosure_basis: 'Fictional only',
    status: 'draft',
    allowed_actions: ['review'],
    version: 1,
    identity_evidence: 'Synthetic',
    reviewed_at: null,
    received_at: null,
    reconciliation_id: null,
    snapshot_sha256: 'fictional',
    snapshot: {
        captured_at: '2026-10-07T01:00:00Z',
        person: { name: 'Fictional person', date_of_birth: '1980-01-01' },
        medications: [
            {
                id: 4,
                version: 2,
                state: 'active',
                approval_status: 'pending',
                last_dose: {
                    given_at: '2026-10-06T19:00:00Z',
                    dose_given: '1 tablet',
                    source: 'source_provider_emar',
                },
                next_due_at: '2026-10-07T07:00:00Z',
                prescription: {
                    name: 'Fictional tablet',
                    dosage: '1 tablet',
                    dose_amount: 1,
                    dose_unit: 'tablet',
                    frequency: 'Twice daily',
                    frequency_code: null,
                    dose_times: ['08:00', '20:00'],
                    is_prn: false,
                    route: 'oral',
                    form: 'tablet',
                    instructions: 'Take with synthetic food',
                    indication: 'Fictional purpose',
                    prn_reason: null,
                    max_per_day: null,
                    min_hours_between_doses: null,
                    start_date: '2026-10-01',
                    end_date: null,
                    prescriber: 'Fictional doctor',
                    pharmacy: 'Fictional pharmacy',
                    controlled_drug: true,
                    high_risk: true,
                    witness_required: true,
                },
            },
        ],
        allergies: [
            {
                allergen: 'Fictional allergen',
                reaction: 'Fictional reaction',
                severity: 'Severe',
                notes: 'Check the source note',
            },
        ],
    },
};
it('makes timing, source dose evidence, pending status and allergy notes available before review', () => {
    render(<HandoverFacts transfer={transfer} />);
    expect(screen.getByText(/08:00, 20:00/)).toBeInTheDocument();
    expect(
        screen.getByText(/active · pending · Version 2/),
    ).toBeInTheDocument();
    expect(screen.getByText('Take with synthetic food')).toBeInTheDocument();
    expect(
        screen.getByText(
            /Controlled medicine · High risk · Second person required/,
        ),
    ).toBeInTheDocument();
    expect(screen.getByText('Last recorded given dose')).toBeInTheDocument();
    expect(screen.getByText('Next recorded due time')).toBeInTheDocument();
    expect(screen.getByText(/Check the source note/)).toBeInTheDocument();
});
it('keeps incoming evidence unverified and distinguishes missing dose facts', () => {
    render(
        <HandoverFacts
            transfer={{
                ...transfer,
                direction: 'incoming',
                snapshot: {
                    ...transfer.snapshot,
                    medications: [
                        {
                            prescription:
                                transfer.snapshot.medications![0].prescription,
                            last_dose: null,
                            next_due_at: null,
                        },
                    ],
                },
            }}
        />,
    );
    expect(screen.getByText('Unverified provider facts')).toBeInTheDocument();
    expect(
        screen.getByText('No given dose recorded in this snapshot'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('No future due time recorded in this snapshot'),
    ).toBeInTheDocument();
});
