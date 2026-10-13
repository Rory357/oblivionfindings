import { describe, expect, it, vi } from 'vitest';
import { ApproverAttemptJournal } from './restriction-approver-journal';
import type { ApproverSaveCorrelation } from './restriction-approver-contract';

function memoryStorage() {
    const entries = new Map<string, string>();
    return {
        entries,
        get length() { return entries.size; },
        key: (index: number) => [...entries.keys()][index] ?? null,
        getItem: (key: string) => entries.get(key) ?? null,
        setItem: (key: string, value: string) => { entries.set(key, value); },
        removeItem: (key: string) => { entries.delete(key); },
    };
}
const attempt: ApproverSaveCorrelation = {
    actorId: 4,
    command: { command_id: '7d886442-95d4-4bb6-a8b5-364489f0d22d', expected_revision: 'b'.repeat(64) },
    intentHash: 'c'.repeat(64),
};
const saved = {
    status: 'saved', result: {
        version: 1, actor_id: 4, command_id: attempt.command.command_id,
        action: 'hr.workRestrictions.approvers.updated', source_key: 'hr.work_restriction_approvers',
        prior_revision: 'b'.repeat(64), revision: 'd'.repeat(64), intent_hash: 'c'.repeat(64),
    },
};

describe('original approver save correlation journal', () => {
    it('recovers outcome identity after a new owner without storing people or reasons', () => {
        const storage = memoryStorage();
        const journal = new ApproverAttemptJournal(storage);
        const fullAttempt = { ...attempt, command: { ...attempt.command,
            authorized_user_ids: [7, 9], reason: 'Private manager explanation' } };
        expect(journal.remember(fullAttempt)).toBe(true);
        expect(new ApproverAttemptJournal(storage).read(4)).toEqual({ status: 'available', attempts: [attempt] });
        const raw = [...storage.entries.values()][0];
        expect(raw).not.toContain('Private manager explanation');
        expect(raw).not.toContain('authorized_user_ids');
        expect(raw).not.toContain('reason');
    });

    it('never replaces the original intent under the same UUID', () => {
        const storage = memoryStorage();
        const journal = new ApproverAttemptJournal(storage);
        expect(journal.remember(attempt)).toBe(true);
        expect(journal.remember({ ...attempt, intentHash: 'f'.repeat(64) })).toBe(false);
        expect(journal.read(4).attempts).toEqual([attempt]);
    });

    it('isolates account reads and leaves unrelated session entries intact', () => {
        const storage = memoryStorage();
        storage.setItem('emar.other-work', 'Preserved');
        const journal = new ApproverAttemptJournal(storage);
        journal.remember(attempt);
        expect(journal.read(5)).toEqual({ status: 'available', attempts: [] });
        expect(journal.settle(attempt, saved)).toEqual({ confirmed: true, cleared: true });
        expect(storage.getItem('emar.other-work')).toBe('Preserved');
    });

    it('does not clear uncertainty from not_confirmed or a different actor, UUID or intent', () => {
        const journal = new ApproverAttemptJournal(memoryStorage());
        journal.remember(attempt);
        expect(journal.settle(attempt, { status: 'not_confirmed', result: null })).toEqual({ confirmed: false, cleared: false });
        for (const delta of [{ actor_id: 5 }, { command_id: 'another' }, { intent_hash: 'f'.repeat(64) }]) {
            expect(journal.settle(attempt, { ...saved, result: { ...saved.result, ...delta } })).toEqual({ confirmed: false, cleared: false });
        }
        expect(journal.read(4).attempts).toEqual([attempt]);
    });

    it('reports corrupt current-account correlation as unavailable rather than an empty safe journal', () => {
        const storage = memoryStorage();
        const journal = new ApproverAttemptJournal(storage);
        journal.remember(attempt);
        const key = [...storage.entries.keys()][0];
        storage.setItem(key, JSON.stringify({ version: 2 }));
        expect(journal.read(4)).toEqual({ status: 'unavailable', attempts: [] });
        expect(journal.remember(attempt)).toBe(false);
    });

    it('refuses an empty-safe inference when an enumerated original attempt cannot be read back', () => {
        const storage = memoryStorage();
        expect(new ApproverAttemptJournal(storage).remember(attempt)).toBe(true);
        const missingReadback = Object.create(storage) as typeof storage;
        missingReadback.getItem = () => null;
        expect(new ApproverAttemptJournal(missingReadback).read(4)).toEqual({ status: 'unavailable', attempts: [] });
    });

    it('rejects storage failures and missing write readback', () => {
        const storage = memoryStorage();
        const ignoredWrite = Object.create(storage) as typeof storage;
        ignoredWrite.setItem = vi.fn();
        expect(new ApproverAttemptJournal(ignoredWrite).remember(attempt)).toBe(false);
        expect(new ApproverAttemptJournal(storage).remember(attempt)).toBe(true);
        const unavailable = Object.create(storage) as typeof storage;
        unavailable.getItem = () => { throw new Error('Storage unavailable'); };
        expect(new ApproverAttemptJournal(unavailable).remember(attempt)).toBe(false);
        expect(new ApproverAttemptJournal(unavailable).read(4).status).toBe('unavailable');
    });

    it('retains the descriptor if clearing a confirmed outcome fails', () => {
        const storage = memoryStorage();
        const ignoredRemove = Object.create(storage) as typeof storage;
        ignoredRemove.removeItem = vi.fn();
        const journal = new ApproverAttemptJournal(ignoredRemove);
        expect(journal.remember(attempt)).toBe(true);
        expect(journal.settle(attempt, saved)).toEqual({ confirmed: true, cleared: false });
        expect(storage.length).toBe(1);
    });
});
