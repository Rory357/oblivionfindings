import { describe, expect, it } from 'vitest';
import {
    acquireCallBrowserLease,
    CallRecoveryJournal,
    callRecoveryKey,
    parseCallRecovery,
    type CallRecoveryRecord,
} from './call-recovery-journal';
import { createCallTestLocks } from './call-recovery-test-locks';

const start: CallRecoveryRecord = {
    version: 1,
    actor: 11,
    device: 'df4ebda0-cb8b-4f20-915f-787cbce1e275',
    conversation: 17,
    kind: 'start',
    mutation: '93c1e5da-608f-4879-9226-2050d6876d28',
    participants: [22],
    routing: 'invited',
    stopping: false,
};
const call: CallRecoveryRecord = {
    version: 1,
    actor: 11,
    device: start.device,
    conversation: 17,
    kind: 'call',
    call: 'call-a',
    invitation: 'invitation-a',
    stopping: 'none',
};
function memory() {
    const values = new Map<string, string>();
    return {
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => {
            values.set(key, value);
        },
        removeItem: (key: string) => {
            values.delete(key);
        },
    };
}
describe('call recovery journal', () => {
    it('stores only allowlisted IDs and rejects another account or sensitive extra data', () => {
        expect(parseCallRecovery(start, 11)).toEqual(start);
        expect(parseCallRecovery(start, 22)).toBeNull();
        for (const extra of [
            { token: 'secret' },
            { title: 'Care content' },
            { audio: { microphone: 'usb' } },
        ])
            expect(parseCallRecovery({ ...start, ...extra }, 11)).toBeNull();
        expect(
            parseCallRecovery({ ...call, call: '../calls/private' }, 11),
        ).toBeNull();
    });
    it('never overwrites malformed storage, a newer attempt, or mismatched scope', () => {
        const storage = memory();
        const journal = new CallRecoveryJournal(storage, 11);
        storage.setItem(callRecoveryKey(11), 'broken');
        expect(journal.write(start, null)).toBe(false);
        expect(storage.getItem(callRecoveryKey(11))).toBe('broken');
        storage.removeItem(callRecoveryKey(11));
        expect(journal.write(start, null)).toBe(true);
        expect(journal.write({ ...start, conversation: 18 }, start)).toBe(
            false,
        );
        expect(
            journal.write({ ...start, device: crypto.randomUUID() }, start),
        ).toBe(false);
        expect(journal.write(call, null)).toBe(false);
        expect(journal.clear(call)).toBe(false);
        expect(journal.read().record).toEqual(start);
    });
    it('preserves stop intent and exact invitation, including end for everyone', () => {
        const journal = new CallRecoveryJournal(memory(), 11);
        expect(journal.write(start, null)).toBe(true);
        const stopped = { ...start, stopping: true };
        expect(journal.write(stopped, start)).toBe(true);
        expect(journal.write(call, stopped)).toBe(false);
        expect(journal.write(start, stopped)).toBe(false);
        expect(journal.clear(stopped)).toBe(true);
        expect(journal.write(call, null)).toBe(true);
        const ended = { ...call, stopping: 'end' as const };
        expect(journal.write(ended, call)).toBe(true);
        expect(journal.write({ ...call, stopping: 'leave' }, ended)).toBe(
            false,
        );
        expect(
            journal.write({ ...ended, invitation: 'replacement' }, ended),
        ).toBe(false);
        expect(journal.clear(call)).toBe(false);
        expect(journal.clear(ended)).toBe(true);
    });
    it('does not claim successful persistence when writing fails or readback differs', () => {
        const storage = memory();
        const journal = new CallRecoveryJournal(
            {
                ...storage,
                setItem: () => {
                    throw new Error('quota');
                },
            },
            11,
        );
        expect(journal.write(start, null)).toBe(false);
        const discarded = new CallRecoveryJournal(
            { ...storage, setItem: () => undefined },
            11,
        );
        expect(discarded.write(start, null)).toBe(false);
    });
    it('allows only one tab per account and releases on abort without timed takeover', async () => {
        const locks = createCallTestLocks();
        const controller = new AbortController();
        const first = await acquireCallBrowserLease(
            11,
            controller.signal,
            locks,
        );
        expect(first).not.toBeNull();
        expect(
            await acquireCallBrowserLease(
                11,
                new AbortController().signal,
                locks,
            ),
        ).toBeNull();
        const other = await acquireCallBrowserLease(
            22,
            new AbortController().signal,
            locks,
        );
        expect(other).not.toBeNull();
        other?.release();
        controller.abort();
        await Promise.resolve();
        await Promise.resolve();
        const replacement = await acquireCallBrowserLease(
            11,
            new AbortController().signal,
            locks,
        );
        expect(replacement).not.toBeNull();
        replacement?.release();
    });
    it('never gives an aborted page ownership and rejects unsupported coordination', async () => {
        const controller = new AbortController();
        controller.abort();
        expect(
            await acquireCallBrowserLease(
                11,
                controller.signal,
                createCallTestLocks(),
            ),
        ).toBeNull();
        await expect(
            acquireCallBrowserLease(
                11,
                new AbortController().signal,
                {} as LockManager,
            ),
        ).rejects.toThrow('coordinate');
    });
});
