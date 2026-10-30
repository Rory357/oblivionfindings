import { setOfflineQueueActor } from '@/lib/offline-queue';
import { beforeEach, describe, expect, it } from 'vitest';
import {
    clearDoseRecovery,
    doseRecoveryKey,
    keepDoseRecovery,
    readDoseRecovery,
} from './recovery';

describe('Unconfirmed dose recovery', () => {
    beforeEach(() => {
        setOfflineQueueActor(null);
        setOfflineQueueActor(71);
    });
    it('keeps the same target separate from other people, medicines and scheduled doses', () => {
        const key = doseRecoveryKey(1, 4, '2026-10-08T08:00:00+13:00');
        const draft = { uuid: 'same-request', body: { status: 'given' } };
        keepDoseRecovery(key, draft);
        expect(
            readDoseRecovery(
                doseRecoveryKey(1, 4, '2026-10-08T08:00:00+13:00'),
            ),
        ).toEqual(draft);
        expect(
            readDoseRecovery(
                doseRecoveryKey(2, 4, '2026-10-08T08:00:00+13:00'),
            ),
        ).toBeNull();
        expect(
            readDoseRecovery(
                doseRecoveryKey(1, 5, '2026-10-08T08:00:00+13:00'),
            ),
        ).toBeNull();
        expect(
            readDoseRecovery(
                doseRecoveryKey(1, 4, '2026-10-08T20:00:00+13:00'),
            ),
        ).toBeNull();
        clearDoseRecovery(key);
        expect(readDoseRecovery(key)).toBeNull();
    });
    it('discards a previous staff member’s pending memory even when they sign in again', () => {
        const key = doseRecoveryKey(1, 4, 'prn');
        keepDoseRecovery(key, { uuid: 'prior-actor' });
        setOfflineQueueActor(72);
        setOfflineQueueActor(71);
        expect(readDoseRecovery(doseRecoveryKey(1, 4, 'prn'))).toBeNull();
    });
    it('warns before leaving while an unconfirmed attempt remains after its dialog closes', () => {
        const key = doseRecoveryKey(1, 4, 'prn');
        keepDoseRecovery(key, { uuid: 'pending' });
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        clearDoseRecovery(key);
        const cleared = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(cleared);
        expect(cleared.defaultPrevented).toBe(false);
    });
});
