import { describe, expect, it } from 'vitest';
import {
    blankTransitDoseFacts,
    validateTransitDoseFacts,
} from './transport-dose-facts';
import {
    buildAdministerMedicationPayload,
    type TransportMedicationLog,
} from './transport-medication-dialogs';
import { validateTransitReturn } from './transport-pack-return-fields';

const log: TransportMedicationLog = {
    id: 1,
    client: { id: 2, name: 'Synthetic person' },
    medication_id: 3,
    medication_name: 'Synthetic medicine',
    is_controlled_drug: false,
    witness_required: false,
    requires_administration_witness: false,
    packed_by: null,
    packed_at: null,
    administered_by: null,
    administered_at: null,
    witnessed_by: null,
    returned_to_house_at: null,
    status: 'packed',
    notes: null,
    pack_stock: {
        stock_id: 1,
        unit: 'tablets',
        lots_started: true,
        lots: [],
        allocations: [
            {
                lot_id: 4,
                outward_movement_id: 6,
                quantity_out: '3',
                quantity_used: '0',
                quantity_returned: '0',
                quantity_missing: '0',
                quantity_remaining_away: '3',
                closed_at: null,
            },
            {
                lot_id: 5,
                outward_movement_id: 7,
                quantity_out: '1',
                quantity_used: '1',
                quantity_returned: '0',
                quantity_missing: '0',
                quantity_remaining_away: '0',
                closed_at: null,
            },
        ],
    },
};
describe('trip pack accounting', () => {
    it('accounts for each retained pack and rejects missing stock without a reason', () => {
        expect(validateTransitReturn(log, [], '')).not.toBeNull();
        expect(
            validateTransitReturn(
                log,
                [
                    { lot_id: 4, quantity: '2', missing: '1' },
                    { lot_id: 5, quantity: '0', missing: '0' },
                ],
                '',
            ),
        ).toMatch(/Explain/);
        expect(
            validateTransitReturn(
                log,
                [
                    { lot_id: 4, quantity: '2', missing: '1' },
                    { lot_id: 5, quantity: '0', missing: '0' },
                ],
                'One tablet missing; house lead contacted',
            ),
        ).toBeNull();
        expect(
            validateTransitReturn(
                log,
                [
                    { lot_id: 4, quantity: '3', missing: '1' },
                    { lot_id: 5, quantity: '0', missing: '0' },
                ],
                'Review',
            ),
        ).toMatch(/account/);
    });
    it('does not infer clinical units or waste from a physical removed quantity', () => {
        const facts = {
            ...blankTransitDoseFacts(),
            quantity_given: '1',
            amount_mode: 'less' as const,
            amount_reason: 'Could not take the full amount',
            quantity_wasted: '1',
            waste_reason: 'Remaining part disposed under policy',
        };
        const lines = [
            { lot_id: 4, revision: 2, quantity: '2', quantity_wasted: '1' },
        ];
        expect(
            validateTransitDoseFacts(facts, lines, '2', null, 'tablet'),
        ).toMatch(/clinical unit/);
        expect(
            validateTransitDoseFacts(
                { ...facts, quantity_wasted: '' },
                lines,
                '2',
                'tablet',
                'tablet',
            ),
        ).toMatch(/including 0/);
        expect(
            validateTransitDoseFacts(facts, lines, '2', 'tablet', 'tablet'),
        ).toBeNull();
        expect(
            validateTransitDoseFacts(
                facts,
                [{ ...lines[0], quantity_wasted: '0' }],
                '2',
                'tablet',
                'tablet',
            ),
        ).toMatch(/match/);
    });
    it('retains exact pack revisions and the stock unit in the administration request', () => {
        const lines = [
            { lot_id: 4, revision: 2, quantity: '2', quantity_wasted: '1' },
        ];
        const body = buildAdministerMedicationPayload({
            quantityAdministered: '2',
            quantityUnit: 'tablet',
            packLines: lines,
            witnessedByUserId: '3',
            witnessCredential: '123456',
            notes: '',
            scan: {
                code: '',
                status: 'idle',
                message: '',
                matchSource: null,
                scanSource: 'manual',
            },
        });
        expect(body).toMatchObject({
            quantity_administered: '2',
            quantity_unit: 'tablet',
            pack_lines: lines,
            witnessed_by_user_id: 3,
        });
    });
});
