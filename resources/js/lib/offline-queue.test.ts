import axios from 'axios';
import { toast } from 'sonner';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
    type Mock,
} from 'vitest';

import {
    __resetOfflineQueueRuntimeForTests,
    __setOfflineQueueStorageForTests,
    queueOfflineSubmission,
    replayOfflineQueue,
    submitOffline,
    type OfflineSubmission,
} from './offline-queue';

vi.mock('axios', () => {
    const request = vi.fn() as Mock & {
        isAxiosError: (error: unknown) => boolean;
    };
    request.isAxiosError = (error: unknown) =>
        Boolean((error as { isAxiosError?: boolean })?.isAxiosError);

    return { default: request };
});

vi.mock('sonner', () => ({
    toast: {
        error: vi.fn(),
        info: vi.fn(),
        success: vi.fn(),
    },
}));

function createStorage(seed: OfflineSubmission[] = []) {
    let items = [...seed];

    return {
        list: vi.fn(async () => [...items]),
        put: vi.fn(async (item: OfflineSubmission) => {
            items = items.filter((existing) => existing.id !== item.id);
            items.push(item);
        }),
        remove: vi.fn(async (id: string) => {
            items = items.filter((item) => item.id !== id);
        }),
        items: () => [...items],
    };
}

function setOnline(value: boolean) {
    Object.defineProperty(window.navigator, 'onLine', {
        configurable: true,
        get: () => value,
    });
}

function queuedSubmission(
    overrides: Partial<OfflineSubmission> = {},
): OfflineSubmission {
    return {
        id: 'queued-1',
        action: 'prn',
        method: 'post',
        url: '/meds/today/prn',
        payload: {
            client_request_uuid: 'queued-1',
            client_medication_id: 123,
            queued_offline: true,
        },
        createdAt: '2026-04-30T09:00:00.000Z',
        lastAttemptAt: null,
        attempts: 0,
        lastError: null,
        ...overrides,
    };
}

describe('offline queue', () => {
    const mockedAxios = axios as unknown as Mock;

    beforeEach(() => {
        __resetOfflineQueueRuntimeForTests();
        window.localStorage.clear();
        setOnline(true);
        mockedAxios.mockReset();
        vi.mocked(toast.info).mockClear();
        vi.mocked(toast.success).mockClear();
        vi.mocked(toast.error).mockClear();
    });

    afterEach(() => {
        __resetOfflineQueueRuntimeForTests();
    });

    it('queues submissions with sync metadata while offline', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_request_uuid: 'offline-uuid',
                client_medication_id: 42,
            },
        });

        expect(result.status).toBe('queued');
        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0].payload).toMatchObject({
            client_request_uuid: 'offline-uuid',
            client_medication_id: 42,
            queued_offline: true,
        });
        expect(storage.items()[0].payload.captured_offline_at).toEqual(
            expect.any(String),
        );
        expect(storage.items()[0].payload.origin_device_id).toEqual(
            expect.any(String),
        );
        expect(toast.info).toHaveBeenCalled();
    });

    it('replays queued submissions and clears successful items', async () => {
        const storage = createStorage([queuedSubmission()]);
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockResolvedValueOnce({ data: { success: true } });

        await replayOfflineQueue();

        expect(mockedAxios).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'post',
                url: '/meds/today/prn',
                data: expect.objectContaining({
                    client_request_uuid: 'queued-1',
                }),
            }),
        );
        expect(storage.items()).toHaveLength(0);
        expect(toast.success).toHaveBeenCalledWith('Queued item sent.');
    });

    it('drops conflict responses and emits the eMAR conflict event', async () => {
        const storage = createStorage([queuedSubmission()]);
        __setOfflineQueueStorageForTests(storage);
        const conflictSpy = vi.fn();
        window.addEventListener('emar:offline-conflict', conflictSpy);

        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 409,
                data: {
                    sync: {
                        message: 'Medication state changed.',
                    },
                },
            },
        });

        await replayOfflineQueue();

        expect(storage.items()).toHaveLength(0);
        expect(conflictSpy).toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith('Medication state changed.');

        window.removeEventListener('emar:offline-conflict', conflictSpy);
    });

    it('drops retryable failures after the eighth attempt', async () => {
        const storage = createStorage([
            queuedSubmission({
                attempts: 7,
                lastError: 'previous failure',
            }),
        ]);
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Network error',
        });

        await replayOfflineQueue();

        expect(storage.items()).toHaveLength(0);
        expect(toast.error).toHaveBeenCalledWith(
            'A queued item could not be saved to the server. Please re-enter it.',
        );
    });

    it('allows legacy wrappers to seed the shared queue directly', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);

        await queueOfflineSubmission({
            action: 'administration',
            url: '/api/medications/clients/1/medications/2/administrations',
            payload: {
                client_request_uuid: 'legacy-uuid',
            },
            createdAt: '2026-04-30T10:00:00.000Z',
        });

        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0]).toMatchObject({
            action: 'administration',
            id: 'legacy-uuid',
            createdAt: '2026-04-30T10:00:00.000Z',
        });
    });
});
