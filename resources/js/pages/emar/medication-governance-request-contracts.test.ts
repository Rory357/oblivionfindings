import { describe, expect, it, vi } from 'vitest';

import {
    createMedicationMutationReplayState,
    prepareMedicationMutationReplayState,
} from '@/lib/emar-offline';

import { controlledDrugSnapshotFromSelection } from './_prescription-dialogs';
import {
    buildControlledStockCountRequest,
    createControlledStockCountReplayState,
    prepareControlledStockCountReplayState,
} from './_stock-dialogs';
import {
    buildCdRegisterRequest,
    CD_REGISTER_ENTRY_TYPES,
} from './components/cd-register-modal';
import {
    addMedicationStockQuantities,
    buildControlledPharmacyDeliveryRequest,
    controlledPharmacyDeliveryPath,
    genericStockMedications,
    medicationStockQuantitiesEqual,
    pharmacyOrderAdvanceAction,
    stockItemQuantityDestination,
    subtractMedicationStockQuantities,
} from './medication-stock-governance';

describe('controlled medication request contracts', () => {
    it('keeps exact material retries stable and rotates after a material edit', () => {
        const createUuid = vi
            .fn()
            .mockReturnValueOnce('first-v4')
            .mockReturnValueOnce('rotated-v4');
        const initial = createMedicationMutationReplayState(createUuid);
        const attempted = prepareMedicationMutationReplayState(
            initial,
            { client_id: 7, quantity: '1.00' },
            createUuid,
        );
        const reorderedExactRetry = prepareMedicationMutationReplayState(
            attempted,
            { quantity: '1.00', client_id: 7 },
            createUuid,
        );
        const corrected = prepareMedicationMutationReplayState(
            reorderedExactRetry,
            { client_id: 7, quantity: '2.00' },
            createUuid,
        );

        expect(attempted.uuid).toBe('first-v4');
        expect(reorderedExactRetry.uuid).toBe('first-v4');
        expect(corrected.uuid).toBe('rotated-v4');
        expect(createUuid).toHaveBeenCalledTimes(2);
    });
    it('submits an explicit immutable classification for unlinked prescriber orders', () => {
        expect(controlledDrugSnapshotFromSelection('ordinary')).toBe(false);
        expect(controlledDrugSnapshotFromSelection('controlled')).toBe(true);
        expect(controlledDrugSnapshotFromSelection('')).toBeNull();
    });

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
                uuid: '2af245d6-fbb2-4b9b-8936-7a995847af2e',
            }),
        ).toMatchObject({
            client_id: 7,
            client_medication_id: 41,
            medication_name: 'Morphine sulfate',
            expected_balance: '9.5',
            actual_balance: '9.00',
            witnessed_by: 19,
            witness_credential: 'witness-secret',
            client_request_uuid: '2af245d6-fbb2-4b9b-8936-7a995847af2e',
        });
    });

    it('keeps one controlled-count replay key for unchanged retries and rotates it for a corrected material request', () => {
        const firstUuid = vi.fn(() => 'first-request-uuid');
        const rotatedUuid = vi.fn(() => 'corrected-request-uuid');
        const payload = buildControlledStockCountRequest({
            clientId: 7,
            clientMedicationId: '41',
            medicationName: 'Morphine sulfate',
            expectedBalance: 9.5,
            actualBalance: '9.00',
            witnessedBy: '19',
            witnessCredential: 'wrong-secret',
            note: 'Counted twice',
            immediateActionTaken: 'Stock secured',
            uuid: 'first-request-uuid',
        });

        const initial = createControlledStockCountReplayState(firstUuid);
        const attempted = prepareControlledStockCountReplayState(
            initial,
            payload,
            rotatedUuid,
        );
        const unchangedRetry = prepareControlledStockCountReplayState(
            attempted,
            {
                ...payload,
                witness_credential: 'correct-secret',
            },
            rotatedUuid,
        );

        expect(initial).toEqual({
            uuid: 'first-request-uuid',
            fingerprint: null,
        });
        expect(attempted.uuid).toBe('first-request-uuid');
        expect(unchangedRetry.uuid).toBe('first-request-uuid');
        expect(rotatedUuid).not.toHaveBeenCalled();

        const correctedCount = prepareControlledStockCountReplayState(
            unchangedRetry,
            {
                ...payload,
                actual_balance: '8.75',
            },
            rotatedUuid,
        );

        expect(correctedCount.uuid).toBe('corrected-request-uuid');
        expect(rotatedUuid).toHaveBeenCalledOnce();
    });

    it('starts a fresh controlled-count replay lifecycle after success or reset', () => {
        expect(
            createControlledStockCountReplayState(
                () => 'next-controlled-count-request',
            ),
        ).toEqual({
            uuid: 'next-controlled-count-request',
            fingerprint: null,
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

    it('reconciles controlled balances in integer hundredths without binary-float drift', () => {
        expect(addMedicationStockQuantities('0.10', '0.20')).toBe('0.30');
        expect(subtractMedicationStockQuantities('0.30', '0.10')).toBe('0.20');
        expect(subtractMedicationStockQuantities('0.10', '0.20')).toBe('');
        expect(medicationStockQuantitiesEqual('0.3', '0.30')).toBe(true);
        expect(
            medicationStockQuantitiesEqual('0.30000000000000004', '0.30'),
        ).toBe(false);
    });
});
