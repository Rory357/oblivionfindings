import { webcrypto } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { prepareHandoverCommand } from './handover-command-prepare';
import type { HandoverCommandSource } from './handover-command-result';

const requestId = '7d886442-95d4-4bb6-a8b5-364489f0d22d';
const source: HandoverCommandSource = {
    version: 1, scope: 'operations_handover_command_source', actor_id: 4, shift_id: 20,
    handover_id: 12, expected_version: 3, status: 'draft', expected_source: 'a'.repeat(64),
    can: { create: false, update: true, submit: true },
};
const fields = { handover_notes: 'Aroha needs support after dinner.', submit: false, follow_up_items_text: '' };
const crypto = webcrypto.subtle as unknown as SubtleCrypto;
const lease = { source, isCurrent: () => true };

describe('operations handover command preparation', () => {
    it('hashes the canonical copied wire fields with actual WebCrypto and preserves explicit clears', async () => {
        const result = await prepareHandoverCommand(lease, 'update', requestId, fields, crypto);
        expect(result?.intentJson).toBe(`{"action":"update","expected_source":"${'a'.repeat(64)}","expected_version":3,"fields":{"follow_up_items_text":"","handover_notes":"Aroha needs support after dinner.","submit":false},"handover_id":12,"shift_id":20}`);
        expect(result?.identity.intent_hash).toBe('cabf0a0e29a3c2cc09b083422f48892cf9d9664eaad47fac683901434a01bfe0');
        expect(result?.fields.follow_up_items_text).toBe('');
        expect(Object.isFrozen(result?.fields)).toBe(true);
        expect(Object.isFrozen(result?.identity)).toBe(true);
    });

    it('does not retain witness proof or unknown actor, status, private DTO or undefined wire fields', async () => {
        for (const extra of [
            { cd_witness_credential: 'SECRET' }, { actor_id: 4 }, { status: 'draft' },
            { client: { name: 'Private' } }, { cd_notes: undefined },
        ]) expect(await prepareHandoverCommand(lease, 'update', requestId, { ...fields, ...extra }, crypto)).toBeNull();
    });

    it('requires exact current action, original UUID and primitive IDs without coercion', async () => {
        expect(await prepareHandoverCommand(lease, 'create', requestId, fields, crypto)).toBeNull();
        expect(await prepareHandoverCommand(lease, 'update', `${requestId}\n`, fields, crypto)).toBeNull();
        expect(await prepareHandoverCommand(lease, 'update', requestId, { ...fields, incoming_staff_id: '9' }, crypto)).toBeNull();
        expect(await prepareHandoverCommand(lease, 'update', requestId, { ...fields, submit: 1 }, crypto)).toBeNull();
        expect(await prepareHandoverCommand({ ...lease, source: { ...source, can: { ...source.can, update: false } } }, 'update', requestId, fields, crypto)).toBeNull();
    });

    it('binds create absence and dedicated submit to different exact intents', async () => {
        const createSource = { ...source, handover_id: null, expected_version: null, status: null, can: { create: true, update: false, submit: false } };
        const create = await prepareHandoverCommand({ ...lease, source: createSource }, 'create', requestId, { ...fields, submit: true }, crypto);
        expect(create?.identity.requested_handover_id).toBeNull();
        expect(create?.expectedStatus).toBe('submitted');
        const submit = await prepareHandoverCommand(lease, 'submit', requestId, {}, crypto);
        expect(submit?.fields).toEqual({});
        expect(submit?.expectedStatus).toBe('submitted');
        expect(await prepareHandoverCommand(lease, 'submit', requestId, fields, crypto)).toBeNull();
    });

    it('copies before hashing and rejects a permanently withdrawn lease after the asynchronous digest', async () => {
        let current = true;
        let finishDigest: (value: ArrayBuffer) => void = () => {};
        const controlledDigest: Pick<SubtleCrypto, 'digest'> = {
            digest: () => new Promise<ArrayBuffer>((resolve) => { finishDigest = resolve; }),
        };
        const input = { ...fields };
        const preparation = prepareHandoverCommand({ source, isCurrent: () => current }, 'update', requestId, input, controlledDigest);
        input.handover_notes = 'Later typing';
        current = false;
        finishDigest(new ArrayBuffer(32));
        expect(await preparation).toBeNull();
    });

    it('retains the original typing after a delayed digest and refuses failed cryptography', async () => {
        let finishDigest: (value: ArrayBuffer) => void = () => {};
        const controlledDigest: Pick<SubtleCrypto, 'digest'> = {
            digest: () => new Promise<ArrayBuffer>((resolve) => { finishDigest = resolve; }),
        };
        const input = { ...fields };
        const preparation = prepareHandoverCommand(lease, 'update', requestId, input, controlledDigest);
        input.handover_notes = 'Later typing';
        finishDigest(new ArrayBuffer(32));
        expect((await preparation)?.fields.handover_notes).toBe(fields.handover_notes);
        const failedDigest: Pick<SubtleCrypto, 'digest'> = { digest: () => Promise.reject(new Error('Unavailable')) };
        expect(await prepareHandoverCommand(lease, 'update', requestId, fields, failedDigest)).toBeNull();
        expect(await prepareHandoverCommand(lease, 'update', requestId, { ...fields, handover_notes: '\uD800' }, crypto)).toBeNull();
    });

    it('retains literal Unicode, slashes and line separators without normalising clinical text', async () => {
        const notes = 'Kia ora / whānau\u2028Aroha\u2029💛';
        const result = await prepareHandoverCommand(lease, 'update', requestId, { handover_notes: notes, submit: false }, crypto);
        expect(result?.fields.handover_notes).toBe(notes);
        expect(result?.intentJson).toContain(notes);
        expect(result?.identity.intent_hash).toBe('25bc9386c690894f3494610af769e8ca1a4dfb186d8c97b76cd009ea73621647');
    });
});
