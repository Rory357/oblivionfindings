import { submitEmarMutation } from '@/lib/emar-offline';
import {
    __resetOfflineQueueRuntimeForTests,
    __setOfflineQueueStorageForTests,
    replayOfflineQueue,
    setOfflineQueueActor,
    type OfflineSubmission,
} from '@/lib/offline-queue';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
vi.mock('axios', () => {
    const request = vi.fn();
    return {
        default: Object.assign(request, {
            isAxiosError: (error: unknown) =>
                Boolean((error as { isAxiosError?: boolean })?.isAxiosError),
        }),
    };
});
vi.mock('sonner', () => ({
    toast: { error: vi.fn(), info: vi.fn(), success: vi.fn() },
}));
const payload = {
    client_request_uuid: '76da6f12-2055-4d85-b7c3-e34ef998ae69',
    direction: 'less',
    said: 'Staff please.',
    occurred_at: '2026-10-03T06:59+13:00',
    client_medication_id: null,
};
const online = (value: boolean) =>
    Object.defineProperty(navigator, 'onLine', {
        configurable: true,
        get: () => value,
    });
function durableStorage() {
    let rows: OfflineSubmission[] = [];
    return {
        list: vi.fn(async () => [...rows]),
        put: vi.fn(async (row: OfflineSubmission) => {
            rows = [...rows.filter((r) => r.id !== row.id), row];
        }),
        remove: vi.fn(async (id: string) => {
            rows = rows.filter((r) => r.id !== id);
        }),
        rows: () => rows,
    };
}
describe('support consent saved actions', () => {
    beforeEach(() => {
        __resetOfflineQueueRuntimeForTests();
        setOfflineQueueActor(101);
        localStorage.clear();
        online(false);
        vi.mocked(axios).mockReset();
    });
    afterEach(() => __resetOfflineQueueRuntimeForTests());
    it('survives a runtime restart and replays only after a confirmed server acknowledgement', async () => {
        const storage = durableStorage();
        __setOfflineQueueStorageForTests(storage);
        expect(
            (
                await submitEmarMutation(
                    '/emar/self-admin/9/consent',
                    payload,
                    { action: 'support_consent' },
                )
            ).status,
        ).toBe('queued');
        expect(storage.rows()).toHaveLength(1);
        expect(storage.rows()[0].actorId).toBe('101');
        __resetOfflineQueueRuntimeForTests();
        __setOfflineQueueStorageForTests(storage);
        setOfflineQueueActor(101);
        online(true);
        vi.mocked(axios).mockResolvedValue({
            data: { sync: { status: 'processed' } },
        });
        await replayOfflineQueue();
        expect(storage.rows()).toHaveLength(0);
        expect(axios).toHaveBeenCalledWith(
            expect.objectContaining({
                url: '/emar/self-admin/9/consent',
                data: expect.objectContaining(payload),
            }),
        );
    });
    it('keeps a rejected stale assessment on the device for attention', async () => {
        const storage = durableStorage();
        __setOfflineQueueStorageForTests(storage);
        await submitEmarMutation('/emar/self-admin/9/consent', payload, {
            action: 'support_consent',
        });
        online(true);
        vi.mocked(axios).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 409,
                data: { message: 'Reload the current support plan.' },
            },
        });
        await replayOfflineQueue();
        expect(storage.rows()).toHaveLength(1);
        expect(storage.rows()[0].rejected).toBe(true);
    });
    it('does not send another signed-in worker’s saved consent', async () => {
        const storage = durableStorage();
        __setOfflineQueueStorageForTests(storage);
        await submitEmarMutation('/emar/self-admin/9/consent', payload, {
            action: 'support_consent',
        });
        setOfflineQueueActor(102);
        online(true);
        await replayOfflineQueue();
        expect(axios).not.toHaveBeenCalled();
        expect(storage.rows()).toHaveLength(1);
    });
    it('reports storage failure without claiming a local save', async () => {
        const storage = durableStorage();
        storage.put.mockRejectedValueOnce(new Error('Disk full'));
        __setOfflineQueueStorageForTests(storage);
        expect(
            (
                await submitEmarMutation(
                    '/emar/self-admin/9/consent',
                    payload,
                    { action: 'support_consent' },
                )
            ).status,
        ).toBe('storage_unavailable');
        expect(storage.rows()).toHaveLength(0);
    });
});
