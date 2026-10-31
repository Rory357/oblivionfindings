import { expect, it } from 'vitest';
import { confirmsInjuryReceipt, injuryCommandAllowed } from './injury-command-state';
import type { InjuryRecord } from './injury-workspace-types';

const expected = {
    injuryId: 9, version: 4, actorId: 12,
    key: '17fd345d-a0f4-4aa1-8f24-6f992ff3d455', command: 'edit' as const,
};
const accepted = {
    accepted: true, injury_id: 9, source_type: 'injury', source_id: 9,
    actor_id: 12, command: 'update', idempotency_key: expected.key,
    expected_version: 4, version: 5, source_version_id: 16,
    changed: true, receipt_id: 21, replayed: false,
};

it('confirms a reconstructed receipt while distinguishing an actual no-op and an accepted replay', () => {
    expect(confirmsInjuryReceipt(accepted, expected)).toBe(true);
    expect(confirmsInjuryReceipt({ ...accepted, replayed: true }, expected)).toBe(true);
    expect(confirmsInjuryReceipt({ ...accepted, changed: false, version: 4 }, expected)).toBe(true);
    expect(confirmsInjuryReceipt({ ...accepted, changed: false }, expected)).toBe(false);
    expect(confirmsInjuryReceipt({ ...accepted, version: 4 }, expected)).toBe(false);
});

it('rejects another actor, source, revision, command or identity and unproven receipt metadata', () => {
    for (const patch of [
        { actor_id: 13 }, { injury_id: 10 }, { source_id: 10 },
        { source_type: 'rtw_plan' }, { expected_version: 3 }, { command: 'transition' },
        { idempotency_key: 'another-command' }, { receipt_id: 0 },
        { source_version_id: null }, { accepted: 'true' }, { changed: 1 },
        { injury_id: '9' }, { replayed: null }, { private_notes: 'Copied medical fact' },
    ]) expect(confirmsInjuryReceipt({ ...accepted, ...patch }, expected)).toBe(false);
    const withoutReceipt: Partial<typeof accepted> = { ...accepted };
    delete withoutReceipt.receipt_id;
    expect(confirmsInjuryReceipt(withoutReceipt, expected)).toBe(false);
    expect(confirmsInjuryReceipt([accepted], expected)).toBe(false);
});

it('requires the exact selected recovery or evidence child without treating a saved arrangement as approval', () => {
    const plan = { ...accepted, command: 'update_rtw_plan', source_type: 'rtw_plan', source_id: 3 };
    expect(confirmsInjuryReceipt(plan, { ...expected, command: 'edit_rtw', childId: 3 })).toBe(true);
    expect(confirmsInjuryReceipt(plan, { ...expected, command: 'edit_rtw', childId: 4 })).toBe(false);
    expect(confirmsInjuryReceipt({ ...plan, approved: true }, { ...expected, command: 'edit_rtw', childId: 3 })).toBe(false);
    const removal = { ...accepted, command: 'remove_evidence', source_type: 'attachment', source_id: 7 };
    expect(confirmsInjuryReceipt(removal, { ...expected, command: 'remove_evidence', childId: 7 })).toBe(true);
    expect(confirmsInjuryReceipt(removal, { ...expected, command: 'remove_evidence', childId: 8 })).toBe(false);
});

it('allows factual create confirmation without requiring enduring record read or inventing a known injury', () => {
    const capture = { ...accepted, command: 'create', expected_version: null, version: 1 };
    const createExpected = { actorId: 12, key: expected.key.toUpperCase(), command: 'create' as const };
    expect(confirmsInjuryReceipt(capture, createExpected)).toBe(true);
    expect(confirmsInjuryReceipt({ ...capture, version: 2 }, createExpected)).toBe(false);
    expect(confirmsInjuryReceipt({ ...capture, changed: false }, createExpected)).toBe(false);
});

it('shapes commands by current source authority, lifecycle and exact visible child instead of URL hints', () => {
    const record = {
        status: 'under_treatment', source_integrity: 'valid', medical_access: 'readable', recovery_integrity: 'valid',
        commands: { expected_version: 4, transitions: ['return_to_work', 'recovered', 'closed'] },
        can: { assign: true, update: true, transition: true, review: true, recovery: true,
            upload_evidence: true, remove_evidence: true },
        rtw_plans: [{ id: 3 }], attachments: [{ id: 7 }],
    } as unknown as InjuryRecord;
    expect(injuryCommandAllowed(record, 'begin_rtw')).toBe(true);
    expect(injuryCommandAllowed(record, 'start_treatment')).toBe(false);
    expect(injuryCommandAllowed(record, 'edit_rtw', { plan: 3 })).toBe(true);
    expect(injuryCommandAllowed(record, 'add_duty', { plan: 4 })).toBe(false);
    expect(injuryCommandAllowed(record, 'remove_evidence', { attachment: 8 })).toBe(false);
    for (const command of ['assign', 'edit', 'close', 'add_capacity', 'upload_evidence'] as const)
        expect(injuryCommandAllowed({ ...record, source_integrity: 'repair_required' }, command)).toBe(false);
    const restricted = { ...record, medical_access: 'restricted' as const, rtw_plans: null, attachments: null };
    for (const command of ['acc', 'review', 'add_rtw', 'add_capacity', 'upload_evidence', 'remove_evidence'] as const)
        expect(injuryCommandAllowed(restricted, command)).toBe(false);
    expect(injuryCommandAllowed(restricted, 'edit')).toBe(true);
    expect(injuryCommandAllowed({ ...record, recovery_integrity: 'repair_required' }, 'add_capacity')).toBe(false);
    expect(injuryCommandAllowed({ ...record, status: 'closed' }, 'edit')).toBe(false);
    expect(injuryCommandAllowed({ ...record, status: 'closed' }, 'assign')).toBe(true);
});
