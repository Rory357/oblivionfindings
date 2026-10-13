import { describe, expect, it } from 'vitest';
import {
    confirmedHandoverCommand,
    currentHandoverCommandSource,
    recoveredHandoverCommand,
    validHandoverCommandIdentity,
    type HandoverCommandIdentity,
} from './handover-command-result';

const identity: HandoverCommandIdentity = {
    version: 1, actor_id: 4, request_id: '7d886442-95d4-4bb6-a8b5-364489f0d22d', action: 'update',
    shift_id: 20, requested_handover_id: 12, expected_version: 3,
    expected_source: 'a'.repeat(64), intent_hash: 'b'.repeat(64),
};
const saved = { handover_id: 12, shift_id: 20, version: 4, status: 'draft', saved_at: '2026-10-12T04:05:06Z' };
const confirmed = { version: 1, scope: 'operations_handover_command', identity, saved, outcome: 'confirmed' };
const source = {
    version: 1, scope: 'operations_handover_command_source', actor_id: 4, shift_id: 20,
    handover_id: 12, expected_version: 5, status: 'submitted', expected_source: 'c'.repeat(64),
    can: { create: false, update: false, submit: false },
};

describe('operations handover original command evidence', () => {
    it('accepts an exact original receipt and freezes its safe saved projection', () => {
        const result = confirmedHandoverCommand(confirmed, identity, 'draft', 200);
        expect(result).toEqual(saved);
        expect(Object.isFrozen(result)).toBe(true);
        expect(result).not.toHaveProperty('handover_notes');
    });

    it('never turns a validation failure, redirect, unconfirmed response or current read into confirmation', () => {
        for (const status of [202, 302, 401, 403, 404, 409, 419, 422, 500]) {
            expect(confirmedHandoverCommand(confirmed, identity, 'draft', status)).toBeNull();
        }
        expect(confirmedHandoverCommand({ ...confirmed, outcome: 'unconfirmed', saved: null }, identity, 'draft', 200)).toBeNull();
        expect(confirmedHandoverCommand(source, identity, 'draft', 200)).toBeNull();
    });

    it('requires every original identity field and rejects coercion, suffixes and unrelated accounts or records', () => {
        const changed = [
            { actor_id: 5 }, { request_id: 'd7b1e824-942a-4ac9-8ef2-fbd4d895bd33' },
            { action: 'submit' }, { shift_id: 21 }, { requested_handover_id: 13 },
            { expected_version: 4 }, { expected_source: 'c'.repeat(64) }, { intent_hash: 'd'.repeat(64) },
            { expected_version: '3' }, { intent_hash: `${'b'.repeat(64)}\n` },
        ];
        for (const delta of changed) {
            expect(confirmedHandoverCommand({ ...confirmed, identity: { ...identity, ...delta } }, identity, 'draft', 200)).toBeNull();
        }
        expect(validHandoverCommandIdentity({ ...identity, request_id: `${identity.request_id}\n` })).toBe(false);
    });

    it('requires current wire shape, literal saved status and a real explicit UTC timestamp', () => {
        for (const delta of [
            { handover_id: 13 }, { shift_id: 21 }, { version: 3 }, { version: '4' },
            { status: 'submitted' }, { saved_at: '2026-02-30T04:05:06Z' },
            { saved_at: '2026-10-12T04:05:06+00:00' }, { saved_at: '2026-10-12T04:05:06.000Z' },
            { handover_notes: 'Unexpected clinical text' },
        ]) {
            expect(confirmedHandoverCommand({ ...confirmed, saved: { ...saved, ...delta } }, identity, 'draft', 200)).toBeNull();
        }
        expect(confirmedHandoverCommand({ ...confirmed, flash: 'Saved' }, identity, 'draft', 200)).toBeNull();
        expect(confirmedHandoverCommand({ ...confirmed, identity: { ...identity, actor: 'Injected' } }, identity, 'draft', 200)).toBeNull();
    });

    it('distinguishes create, update and dedicated submit without inventing a saved row for create', () => {
        const create: HandoverCommandIdentity = { ...identity, action: 'create', requested_handover_id: null, expected_version: null };
        expect(validHandoverCommandIdentity(create)).toBe(true);
        expect(confirmedHandoverCommand({ ...confirmed, identity: create }, create, 'draft', 200)).toEqual(saved);
        expect(validHandoverCommandIdentity({ ...create, expected_version: 3 })).toBe(false);
        const submit: HandoverCommandIdentity = { ...identity, action: 'submit' };
        expect(confirmedHandoverCommand({ ...confirmed, identity: submit }, submit, 'draft', 200)).toBeNull();
        expect(confirmedHandoverCommand({ ...confirmed, identity: submit, saved: { ...saved, status: 'submitted' } }, submit, 'submitted', 200)?.status).toBe('submitted');
    });

    it('withholds malformed or mismatched current descriptors rather than manufacturing access', () => {
        expect(currentHandoverCommandSource(source, 4, 20)?.can.update).toBe(false);
        for (const delta of [
            { actor_id: 5 }, { shift_id: 21 }, { expected_version: null },
            { handover_id: null }, { expected_source: 'short' }, { status: 'published' },
            { can: { create: false, update: 1, submit: false } }, { handover_notes: 'Private' },
        ]) expect(currentHandoverCommandSource({ ...source, ...delta }, 4, 20)).toBeNull();
        const absent = { ...source, handover_id: null, expected_version: null, status: null };
        expect(currentHandoverCommandSource(absent, 4, 20)?.handover_id).toBeNull();
    });

    it('recovers historical evidence separately from a later lawful current version', () => {
        const lookup = { version: 1, scope: 'operations_handover_attempt', identity, result: confirmed, current: source };
        const result = recoveredHandoverCommand(lookup, identity, 'draft', 200);
        expect(result?.saved?.version).toBe(4);
        expect(result?.current.expected_version).toBe(5);
        expect(result?.current.status).toBe('submitted');
        expect(result?.current.can.update).toBe(false);
    });

    it('keeps a missing witness unknown and refuses a mismatched nested receipt or current account', () => {
        const lookup = { version: 1, scope: 'operations_handover_attempt', identity, result: null, current: source };
        expect(recoveredHandoverCommand(lookup, identity, 'draft', 200)?.saved).toBeNull();
        expect(recoveredHandoverCommand({ ...lookup, result: { ...confirmed, identity: { ...identity, intent_hash: 'd'.repeat(64) } } }, identity, 'draft', 200)).toBeNull();
        expect(recoveredHandoverCommand({ ...lookup, current: { ...source, actor_id: 5 } }, identity, 'draft', 200)).toBeNull();
        expect(recoveredHandoverCommand(lookup, identity, 'draft', 202)).toBeNull();
    });
});
