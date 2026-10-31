import { expect, it } from 'vitest';
import { checkedRecoveryAttempt, checkedRecoveryPage, recoveryStateLabel } from './_recovery-state';

it('distinguishes a retained private evidence transfer from active-record removal', () => {
    const row = { id: 9, purpose: 'legacy_public_origin', state: 'pending', private_reference_recorded: true, public_copy_cleanup: 'pending' };
    expect(checkedRecoveryAttempt(row, 'relocations')?.can_retry).toBe(true);
    expect(checkedRecoveryAttempt({ ...row, state: 'complete', public_copy_cleanup: 'complete', can_retry: true }, 'relocations')?.can_retry).toBe(false);
    for (const patch of [{ purpose: 'removal' }, { private_reference_recorded: false }, { public_copy_cleanup: 'complete' }, { removed_from_record: true }])
        expect(checkedRecoveryAttempt({ ...row, ...patch }, 'relocations')).toBeNull();
    expect(checkedRecoveryAttempt(row, 'removals')).toBeNull();
    expect(recoveryStateLabel('relocations', 'unknown')).toContain('Old public copy');
});

it('validates the exact attempted source and keeps accepted evidence protected from retry', () => {
    expect(checkedRecoveryAttempt({ id: 9, state: 'accepted', accepted: true, can_retry: true }, 'uploads', 9)?.can_retry).toBe(false);
    expect(checkedRecoveryAttempt({ id: 9, state: 'cleaned', accepted: false, can_retry: true }, 'uploads', 9)?.can_retry).toBe(false);
    expect(checkedRecoveryAttempt({ id: 9, state: 'unknown', accepted: false }, 'uploads', 9)?.can_retry).toBe(true);
    expect(checkedRecoveryAttempt({ id: 10, state: 'unknown', accepted: false }, 'uploads', 9)).toBeNull();
    expect(checkedRecoveryAttempt({ id: 9, state: 'unknown', accepted: 'false' }, 'uploads')).toBeNull();
});
it('requires explicit active-record removal proof and never conflates purge status with record acceptance', () => {
    expect(checkedRecoveryAttempt({ id: 9, state: 'pending', removed_from_record: true }, 'removals')?.can_retry).toBe(true);
    expect(checkedRecoveryAttempt({ id: 9, state: 'complete', removed_from_record: true, can_retry: true }, 'removals')?.can_retry).toBe(false);
    expect(checkedRecoveryAttempt({ id: 9, state: 'complete', removed_from_record: false }, 'removals')).toBeNull();
    expect(recoveryStateLabel('uploads', 'unknown')).toContain('not confirmed'); expect(recoveryStateLabel('removals', 'pending')).toContain('pending');
});
it('rejects malformed or duplicated continuation pages without inventing a zero pending count', () => {
    const row = { id: 9, state: 'staged', accepted: false, can_retry: true };
    expect(checkedRecoveryPage({ attempts: [row], next_cursor: 10 }, 'uploads', 8)?.attempts).toHaveLength(1);
    expect(checkedRecoveryPage({ attempts: [row, row], next_cursor: null }, 'uploads')).toBeNull();
    expect(checkedRecoveryPage({ attempts: [row], next_cursor: 8 }, 'uploads', 8)).toBeNull();
    expect(checkedRecoveryPage({ attempts: [row], next_cursor: null }, 'uploads', 9)).toBeNull();
    expect(checkedRecoveryPage({ attempts: null, next_cursor: null }, 'uploads')).toBeNull();
    expect(checkedRecoveryPage({ attempts: [], next_cursor: null }, 'uploads')).toEqual({ attempts: [], next_cursor: null });
});
