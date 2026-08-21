import { describe, expect, it } from 'vitest';

import { buildControlledStockCountRequest } from './_stock-dialogs';
import {
    buildCdRegisterRequest,
    CD_REGISTER_ENTRY_TYPES,
} from './components/cd-register-modal';
import {
    addMedicationStockQuantities,
    buildControlledPharmacyDeliveryRequest,
    controlledPharmacyDeliveryPath,
    genericStockMedications,
    pharmacyOrderAdvanceAction,
    stockItemQuantityDestination,
} from './medication-stock-governance';

describe('controlled medication request contracts', () => {
    it('binds a register movement to the canonical medication and preserves decimal strings and witness credential', () => {
        expect(
            buildCdRegisterRequest({
                clientId: '7',
                clientMedicationId: '41',
                medicationName: 'Morphine sulfate',
                entryType: 'administration',
                quantity: '0.50',
                unit: 'tablets',
                onHandBefore: '10.00',
                onHandAfter: '9.50',
                witnessedBy: '19',
                witnessCredential: 'witness-secret',
                batch: '',
                expiry: '',
                notes: '',
                uuid: '2af245d6-fbb2-4b9b-8936-7a995847af2e',
            }),
        ).toMatchObject({
            client_id: 7,
            client_medication_id: 41,
            medication_name: 'Morphine sulfate',
            quantity: '0.50',
            on_hand_before: '10.00',
            on_hand_after: '9.50',
            witnessed_by: 19,
            witness_credential: 'witness-secret',
        });
    });

    it('binds a controlled count to the canonical medication without converting precise balances through a number', () => {
        expect(
            buildControlledStockCountRequest({
                clientId: 7,
                clientMedicationId: '41',
                medicationName: 'Morphine sulfate',
                expectedBalance: 9.5,
                actualBalance: '9.00',
                witnessedBy: '19',
                witnessCredential: 'witness-secret',
                note: 'Counted twice',
                immediateActionTaken: 'Stock secured',
            }),
        ).toMatchObject({
            client_id: 7,
            client_medication_id: 41,
            medication_name: 'Morphine sulfate',
            expected_balance: '9.5',
            actual_balance: '9.00',
            witnessed_by: 19,
            witness_credential: 'witness-secret',
        });
    });

    it('does not offer balance checks through the register-entry endpoint', () => {
        expect(
            CD_REGISTER_ENTRY_TYPES.map((entryType) => entryType.value),
        ).not.toContain('balance_check');
    });

    it('suppresses controlled selections from generic receive and dashboard stock movement flows', () => {
        const medications = [
            { id: 41, controlled: true },
            { id: 42, controlled: false },
        ];

        expect(genericStockMedications(medications)).toEqual([
            { id: 42, controlled: false },
        ]);
    });

    it('hands controlled stock-item counts to the witnessed balance-check flow', () => {
        expect(stockItemQuantityDestination({ controlled: true })).toBe(
            'controlled-balance-check',
        );
        expect(stockItemQuantityDestination({ controlled: false })).toBe(
            'generic-adjust',
        );
    });

    it('hands only a dispensed controlled order to the controlled delivery command', () => {
        expect(
            pharmacyOrderAdvanceAction({
                controlled: true,
                status: 'dispensed',
            }),
        ).toBe('controlled-delivery');
        expect(
            pharmacyOrderAdvanceAction({
                controlled: true,
                status: 'confirmed',
            }),
        ).toBe('advance');
        expect(
            pharmacyOrderAdvanceAction({
                controlled: false,
                status: 'dispensed',
            }),
        ).toBe('advance');
    });

    it('posts a precise witnessed controlled delivery to the exact order command', () => {
        expect(addMedicationStockQuantities('9.50', '0.50')).toBe('10.00');
        expect(controlledPharmacyDeliveryPath(73)).toBe(
            '/emar/stock/pharmacy-orders/73/controlled-delivery',
        );
        expect(
            buildControlledPharmacyDeliveryRequest({
                clientMedicationId: 41,
                quantityReceived: '0.50',
                onHandBefore: '9.50',
                onHandAfter: '10.00',
                witnessedBy: '19',
                witnessCredential: 'witness-secret',
                deliveryNotes: '',
                uuid: '2af245d6-fbb2-4b9b-8936-7a995847af2e',
            }),
        ).toEqual({
            client_medication_id: 41,
            quantity_received: '0.50',
            on_hand_before: '9.50',
            on_hand_after: '10.00',
            witnessed_by: 19,
            witness_credential: 'witness-secret',
            delivery_notes: null,
            client_request_uuid: '2af245d6-fbb2-4b9b-8936-7a995847af2e',
        });
    });
});
