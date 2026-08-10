import { readFileSync } from 'node:fs';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    AdministerTransportMedicationWizard,
    PackMedicationWizard,
    ReturnTransportMedicationWizard,
    buildAdministerMedicationPayload,
    buildPackMedicationPayload,
    buildReturnMedicationPayload,
    type TransportMedicationLog,
    type TransportMedicationOption,
} from './transport-medication-dialogs';

const idleScan = {
    code: '',
    status: 'idle' as const,
    message: '',
    matchSource: null,
    scanSource: 'manual' as const,
};

const medication: TransportMedicationOption = {
    id: 41,
    name: 'Paracetamol',
    dosage: '500mg',
    frequency: 'Twice daily',
    is_prn: false,
    controlled_drug: false,
    dose_times: ['08:00', '20:00'],
    route: 'Oral',
    instructions: 'Take with food.',
};

const log: TransportMedicationLog = {
    id: 72,
    client: { id: 9, name: 'Alex Resident' },
    medication_id: medication.id,
    medication_name: 'Paracetamol 500mg',
    is_controlled_drug: false,
    packed_by: { id: 3, name: 'Packing Worker' },
    packed_at: '2026-07-13T08:00:00+12:00',
    administered_by: null,
    administered_at: null,
    witnessed_by: null,
    returned_to_house_at: null,
    status: 'packed',
    notes: null,
};

describe('transport medication payload contract', () => {
    it('keeps one canonical payload shape for pack, administer, and return', () => {
        expect(
            buildPackMedicationPayload({
                clientId: 9,
                medication,
                witnessName: '',
                notes: '',
                scan: idleScan,
            }),
        ).toEqual({
            client_id: 9,
            medication_id: 41,
            medication_name: 'Paracetamol',
            is_controlled_drug: false,
            witness_name: null,
            notes: null,
            scan_code: null,
            scan_source: null,
            scan_verified: false,
            scan_match_source: null,
        });

        expect(
            buildAdministerMedicationPayload({
                witnessedByUserId: '18',
                notes: 'Given during transit.',
                scan: idleScan,
            }),
        ).toEqual({
            witnessed_by_user_id: 18,
            notes: 'Given during transit.',
            scan_code: null,
            scan_source: null,
            scan_verified: false,
            scan_match_source: null,
        });

        expect(
            buildReturnMedicationPayload({
                notes: '',
                scan: idleScan,
            }),
        ).toEqual({
            notes: null,
            scan_code: null,
            scan_source: null,
            scan_verified: false,
            scan_match_source: null,
        });
    });
});

describe('transport medication wizard family', () => {
    it('provides accessible pack, administration, and return workflows with review steps', () => {
        const common = {
            onClose: vi.fn(),
            onCompleted: vi.fn(),
        };

        const { unmount } = render(
            <PackMedicationWizard
                open
                transportId={55}
                client={{ id: 9, name: 'Alex Resident' }}
                residentName="Alex Resident"
                medications={[medication]}
                {...common}
            />,
        );

        expect(
            screen.getByRole('dialog', { name: 'Pack medication for transit' }),
        ).toHaveAccessibleDescription(
            'Select medication, complete custody checks, and review before packing it for this transport.',
        );
        expect(screen.getAllByText('Medication & custody')).toHaveLength(2);
        expect(screen.getAllByText('Review')).not.toHaveLength(0);
        unmount();

        const administered = render(
            <AdministerTransportMedicationWizard
                log={log}
                witnesses={[]}
                {...common}
            />,
        );
        expect(
            screen.getByRole('dialog', {
                name: 'Record transport administration',
            }),
        ).toHaveAccessibleDescription(
            'Complete witness and verification checks, then review before recording this administration.',
        );
        administered.unmount();

        render(<ReturnTransportMedicationWizard log={log} {...common} />);
        expect(
            screen.getByRole('dialog', { name: 'Record medication return' }),
        ).toHaveAccessibleDescription(
            'Complete return verification and review before returning this medication to house stock.',
        );
    });

    it('is the only workflow implementation imported by both entry pages', () => {
        const showSource = readFileSync(
            'resources/js/pages/fleet-assets/transports/show.tsx',
            'utf8',
        );
        const indexSource = readFileSync(
            'resources/js/pages/fleet-assets/transports/medications.tsx',
            'utf8',
        );

        for (const source of [showSource, indexSource]) {
            expect(source).toContain(
                "from './components/transport-medication-dialogs'",
            );
            expect(source).not.toMatch(/<DialogContent(?:\s|>)/);
        }
    });
});
