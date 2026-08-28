import { readFileSync } from 'node:fs';

import { describe, expect, it, vi } from 'vitest';

import {
    createTransportMedicationReplayState,
    onlineTransportMedicationEnvelope,
    prepareTransportMedicationReplayState,
} from './transport-medication-replay';

const FIRST_UUID = '11111111-1111-4111-8111-111111111111';
const ROTATED_UUID = '22222222-2222-4222-8222-222222222222';

describe('transport medication replay state', () => {
    it('marks medication-bearing creates as online without adding medication provenance to ordinary transports', () => {
        const ordinaryEnvelope = onlineTransportMedicationEnvelope([]);
        const medicationEnvelope = onlineTransportMedicationEnvelope([
            { medication_id: 31 },
        ]);

        expect(ordinaryEnvelope).toEqual({});
        expect(medicationEnvelope).toEqual({ queued_offline: false });
        expect(medicationEnvelope.queued_offline).toBe(false);
    });

    it('creates a version 4 request UUID by default', () => {
        expect(createTransportMedicationReplayState().uuid).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
        );
    });

    it('retains exact retries and credential-only edits but rotates material edits', () => {
        const rotate = vi.fn(() => ROTATED_UUID);
        let replay = createTransportMedicationReplayState(() => FIRST_UUID);
        const payload = {
            action: 'pack',
            transport_id: 17,
            medication_id: 31,
            notes: 'Original custody note',
            witness_credential: 'first secret',
            client_request_uuid: replay.uuid,
        };

        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        expect(replay.uuid).toBe(FIRST_UUID);

        replay = prepareTransportMedicationReplayState(
            replay,
            {
                ...payload,
                witness_credential: 'corrected secret',
                client_request_uuid: 'ignored request identity',
            },
            rotate,
        );
        expect(replay.uuid).toBe(FIRST_UUID);
        expect(rotate).not.toHaveBeenCalled();

        replay = prepareTransportMedicationReplayState(
            replay,
            { ...payload, notes: 'Materially changed custody note' },
            rotate,
        );
        expect(replay.uuid).toBe(ROTATED_UUID);
        expect(rotate).toHaveBeenCalledOnce();

        replay = prepareTransportMedicationReplayState(
            replay,
            { ...payload, notes: 'Materially changed custody note' },
            rotate,
        );
        expect(replay.uuid).toBe(ROTATED_UUID);
        expect(rotate).toHaveBeenCalledOnce();
    });

    it('excludes credentials recursively for medication-bearing transport creation', () => {
        const rotate = vi.fn(() => ROTATED_UUID);
        let replay = createTransportMedicationReplayState(() => FIRST_UUID);
        const payload = {
            action: 'create_transport_with_medications',
            resident_name: 'Alex Resident',
            medications: [
                {
                    medication_id: 31,
                    witnessed_by_user_id: 9,
                    witness_credential: 'first secret',
                },
            ],
        };

        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        replay = prepareTransportMedicationReplayState(
            replay,
            {
                ...payload,
                medications: [
                    {
                        ...payload.medications[0],
                        witness_credential: 'second secret',
                    },
                ],
            },
            rotate,
        );
        expect(replay.uuid).toBe(FIRST_UUID);

        replay = prepareTransportMedicationReplayState(
            replay,
            {
                ...payload,
                medications: [
                    {
                        ...payload.medications[0],
                        medication_id: 32,
                    },
                ],
            },
            rotate,
        );
        expect(replay.uuid).toBe(ROTATED_UUID);
    });

    it('retains an exact return retry and rotates when return evidence changes', () => {
        const rotate = vi.fn(() => ROTATED_UUID);
        let replay = createTransportMedicationReplayState(() => FIRST_UUID);
        const payload = {
            action: 'return',
            log_id: 72,
            notes: 'Returned to house stock.',
            scan_code: 'verified-code',
        };

        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        expect(replay.uuid).toBe(FIRST_UUID);
        expect(rotate).not.toHaveBeenCalled();

        replay = prepareTransportMedicationReplayState(
            replay,
            { ...payload, notes: 'Changed hand-back evidence.' },
            rotate,
        );
        expect(replay.uuid).toBe(ROTATED_UUID);
        expect(rotate).toHaveBeenCalledOnce();
    });

    it('treats the administered quantity as material replay evidence', () => {
        const rotate = vi.fn(() => ROTATED_UUID);
        let replay = createTransportMedicationReplayState(() => FIRST_UUID);
        const payload = {
            action: 'administer',
            log_id: 72,
            quantity_administered: '0.25',
            scan_code: 'verified-code',
        };

        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        replay = prepareTransportMedicationReplayState(replay, payload, rotate);
        expect(replay.uuid).toBe(FIRST_UUID);
        expect(rotate).not.toHaveBeenCalled();

        replay = prepareTransportMedicationReplayState(
            replay,
            { ...payload, quantity_administered: '0.5' },
            rotate,
        );
        expect(replay.uuid).toBe(ROTATED_UUID);
        expect(rotate).toHaveBeenCalledOnce();
    });

    it('wires shared replay preparation and reset into every required Fleet surface', () => {
        const dialogs = readFileSync(
            'resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx',
            'utf8',
        );
        const create = readFileSync(
            'resources/js/pages/fleet-assets/transports/create.tsx',
            'utf8',
        );

        for (const replayName of [
            'packReplay',
            'correctionReplay',
            'administrationReplay',
            'returnReplay',
        ]) {
            expect(dialogs).toMatch(
                new RegExp(
                    `${replayName}\\.current\\s*=\\s*prepareTransportMedicationReplayState`,
                ),
            );
            expect(dialogs).toMatch(
                new RegExp(
                    `${replayName}\\.current\\s*=\\s*createTransportMedicationReplayState\\(\\)`,
                ),
            );
        }

        expect(create).toMatch(
            /transportReplay\.current\s*=\s*prepareTransportMedicationReplayState/,
        );
        expect(create).toMatch(
            /transportReplay\.current\s*=\s*createTransportMedicationReplayState\(\)/,
        );
        expect(
            create.match(
                /\.\.\.onlineTransportMedicationEnvelope\(medications\)/g,
            ),
        ).toHaveLength(2);
        expect(dialogs).not.toContain('crypto.randomUUID()');
        expect(create).not.toContain('crypto.randomUUID()');
    });
});
