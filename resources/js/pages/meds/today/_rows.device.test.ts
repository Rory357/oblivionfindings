/* Saved on this device (EA-010, EA-116; walk finding 10 Oct): a row whose
 * dose waits on this device says whether it sends on its own or stopped
 * retrying and needs "Retry safely" from the banner. */
import type { OfflineSubmission } from '@/lib/offline-queue';
import { describe, expect, it } from 'vitest';
import { deviceNeedsManualRetry, deviceStateFor } from './_rows';
import type { ScheduleRow } from './types';

const row = {
    key: '93:2026-10-10T08:02',
    medication_id: 93,
    scheduled_for: '2026-10-10T08:02:00+13:00',
    recorded: null,
} as unknown as ScheduleRow;

const item = (over: Partial<OfflineSubmission> = {}): OfflineSubmission =>
    ({
        id: 'b20fd1d0-541a-494f-80ad-6af1f5101b07',
        actorId: '11',
        action: 'administration',
        method: 'post',
        url: '/meds/today/record',
        payload: {
            client_medication_id: 93,
            scheduled_for: '2026-10-10T08:02:00+13:00',
        },
        createdAt: '2026-10-09T19:09:37.180Z',
        attempts: 1,
        ...over,
    }) as OfflineSubmission;

describe('a dose saved on this device', () => {
    it('sends on its own while it is still retrying', () => {
        const queue = { pendingSubmissions: [item()], rejectedSubmissions: [] };
        expect(deviceStateFor(row, queue)).toBe('pending');
        expect(deviceNeedsManualRetry(row, queue)).toBe(false);
    });

    it('needs “Retry safely” once it stopped retrying on its own', () => {
        const queue = {
            pendingSubmissions: [item({ needsAttention: true, attempts: 9 })],
            rejectedSubmissions: [],
        };
        expect(deviceStateFor(row, queue)).toBe('pending');
        expect(deviceNeedsManualRetry(row, queue)).toBe(true);
    });

    it('never matches another dose of the same order', () => {
        const queue = {
            pendingSubmissions: [
                item({
                    needsAttention: true,
                    payload: {
                        client_medication_id: 93,
                        scheduled_for: '2026-10-10T14:36:00+13:00',
                    },
                }),
            ],
            rejectedSubmissions: [],
        };
        expect(deviceStateFor(row, queue)).toBeNull();
        expect(deviceNeedsManualRetry(row, queue)).toBe(false);
    });
});
