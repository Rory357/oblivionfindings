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
    __migrateLegacyEmarQueueForTests,
    submitEmarMutation,
} from './emar-offline';
import {
    __resetOfflineQueueRuntimeForTests,
    __setOfflineQueueStorageForTests,
    EphemeralCredentialQueueError,
    getPendingCount,
    OfflineQueueStorageError,
    queueOfflineSubmission,
    replayOfflineQueue,
    retryOfflineSubmissionsNeedingAttention,
    setOfflineQueueActor,
    submitOffline,
    type OfflineSubmission,
} from './offline-queue';

const UUID_V4_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

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
    const uuid = 'a92be861-e38f-4cb0-8daf-87bd65dfcae7';

    return {
        id: uuid,
        actorId: '101',
        action: 'prn',
        method: 'post',
        url: '/meds/today/prn',
        payload: {
            client_request_uuid: uuid,
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
        setOfflineQueueActor(101);
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
                client_request_uuid: '76da6f12-2055-4d85-b7c3-e34ef998ae69',
                client_medication_id: 42,
            },
        });

        expect(result.status).toBe('queued');
        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0].actorId).toBe('101');
        expect(storage.items()[0].payload).toMatchObject({
            client_request_uuid: '76da6f12-2055-4d85-b7c3-e34ef998ae69',
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

    it('does not claim an offline save when durable queue storage rejects the write', async () => {
        const storage = createStorage();
        const close = vi.fn();
        storage.put.mockRejectedValueOnce(new Error('IndexedDB write failed'));
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_request_uuid: '7d30c3a5-056d-470b-b739-e186057378e6',
                client_medication_id: 42,
            },
        }).then((submission) => {
            if (submission.status === 'queued') close();

            return submission;
        });

        expect(result).toMatchObject({
            status: 'storage_unavailable',
            clientRequestUuid: '7d30c3a5-056d-470b-b739-e186057378e6',
        });
        expect(storage.items()).toHaveLength(0);
        expect(close).not.toHaveBeenCalled();
        expect(toast.info).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('secure offline storage is unavailable'),
        );
    });

    it('does not bind or queue a submission without a current authenticated actor', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        setOfflineQueueActor(null);
        setOnline(false);

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_request_uuid: 'c4ee67a7-ee89-43f7-a890-1b98996838c8',
                client_medication_id: 42,
            },
        });

        expect(result.status).toBe('requires_authentication');
        expect(storage.put).not.toHaveBeenCalled();
        expect(mockedAxios).not.toHaveBeenCalled();
        expect(toast.info).not.toHaveBeenCalled();
    });

    it('sends only online idempotency metadata on the first attempt', async () => {
        mockedAxios.mockResolvedValueOnce({ data: { success: true } });

        await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: { client_medication_id: 42 },
        });

        const sent = mockedAxios.mock.calls[0][0].data as Record<
            string,
            unknown
        >;
        expect(sent.client_request_uuid).toEqual(
            expect.stringMatching(UUID_V4_PATTERN),
        );
        expect(sent.queued_offline).toBe(false);
        expect(sent).not.toHaveProperty('captured_offline_at');
        expect(sent).not.toHaveProperty('origin_device_id');
    });

    it('queues an uncertain-ACK retry with the exact online request fingerprint', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Network error',
        });

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: { client_medication_id: 42 },
        });

        expect(result.status).toBe('queued');
        const sent = mockedAxios.mock.calls[0][0].data as Record<
            string,
            unknown
        >;
        const queued = storage.items()[0].payload;
        expect(sent.queued_offline).toBe(false);
        expect(sent).not.toHaveProperty('captured_offline_at');
        expect(sent).not.toHaveProperty('origin_device_id');
        expect(queued).toEqual(sent);
        expect(queued.queued_offline).toBe(false);
        expect(queued).not.toHaveProperty('captured_offline_at');
        expect(queued).not.toHaveProperty('origin_device_id');
        expect(storage.items()[0].attempts).toBe(1);
    });

    it('does not claim an uncertain-ACK retry was queued when its durable write fails', async () => {
        const storage = createStorage();
        storage.put.mockRejectedValueOnce(new Error('IndexedDB write failed'));
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Network error',
        });

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_medication_id: 42,
                client_request_uuid: '229aa196-d939-43c1-9706-8572013dd77f',
            },
        });

        expect(result).toMatchObject({
            status: 'storage_unavailable',
            clientRequestUuid: '229aa196-d939-43c1-9706-8572013dd77f',
        });
        expect(storage.items()).toHaveLength(0);
        expect(toast.info).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('secure offline storage is unavailable'),
        );
    });

    it('keeps an online-only witnessed mutation and its UUID in memory after an uncertain response', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Network error',
        });

        const result = await submitEmarMutation(
            '/api/medications/clients/1/medications/2/administrations',
            {
                client_request_uuid: 'bd9fd3f4-0792-40b8-93fd-fe2e378f83cd',
                status: 'given',
                witnessed_by: 9,
                witness_credential: 'ephemeral-password',
            },
            { allowQueueWhenOffline: false },
        );

        expect(result).toEqual({
            status: 'requires_connection',
            clientRequestUuid: 'bd9fd3f4-0792-40b8-93fd-fe2e378f83cd',
        });
        expect(storage.put).not.toHaveBeenCalled();
        expect(toast.info).not.toHaveBeenCalled();
    });

    it('keeps a credentialed offline action open without persisting or claiming it was queued', async () => {
        const storage = createStorage();
        const close = vi.fn();
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_medication_id: 42,
                client_request_uuid: 'eafcc8c7-fb02-4843-8128-d62ca80a4a42',
                witness_credential: 'ephemeral-password',
            },
        }).then((submission) => {
            if (submission.status === 'queued') close();

            return submission;
        });

        expect(result).toMatchObject({
            status: 'requires_connection',
            clientRequestUuid: 'eafcc8c7-fb02-4843-8128-d62ca80a4a42',
        });
        expect(storage.items()).toHaveLength(0);
        expect(mockedAxios).not.toHaveBeenCalled();
        expect(close).not.toHaveBeenCalled();
        expect(toast.info).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('credentials are never saved'),
        );
    });

    it('does not persist a credential after an uncertain online acknowledgement', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Network error',
        });

        const result = await submitOffline({
            action: 'prn',
            url: '/meds/today/prn',
            payload: {
                client_medication_id: 42,
                client_request_uuid: '53fb5d68-a1bb-4356-ab66-ed950e3b36fe',
                witness_credential: 'ephemeral-password',
            },
        });

        expect(result).toMatchObject({
            status: 'requires_connection',
            clientRequestUuid: '53fb5d68-a1bb-4356-ab66-ed950e3b36fe',
        });
        expect(mockedAxios).toHaveBeenCalledWith(
            expect.objectContaining({
                data: expect.objectContaining({
                    client_request_uuid: '53fb5d68-a1bb-4356-ab66-ed950e3b36fe',
                    witness_credential: 'ephemeral-password',
                }),
            }),
        );
        expect(storage.items()).toHaveLength(0);
        expect(toast.info).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('credentials are never saved'),
        );
    });

    it.each([
        'witness_credential',
        'cd_witness_credential',
        'read_back_witness_credential',
        'waiver_approver_credential',
        'password',
        'api_token',
        'access_pin',
        'client_secret',
        'authorization',
        'auth',
        'api_key',
        'apiKey',
        'accessToken',
        'refreshToken',
    ])(
        'guards direct queue persistence against nested %s values',
        async (key) => {
            const storage = createStorage();
            __setOfflineQueueStorageForTests(storage);

            await expect(
                queueOfflineSubmission({
                    action: 'cd_destruction',
                    url: '/emar/destructions',
                    payload: {
                        client_request_uuid:
                            '46a2e3bc-b318-4f65-be16-f09d3b736e38',
                        witness: {
                            [key]: 'ephemeral-password',
                        },
                    },
                }),
            ).rejects.toBeInstanceOf(EphemeralCredentialQueueError);
            expect(storage.items()).toHaveLength(0);
        },
    );

    it('does not treat ordinary secret-related identifier fields as credentials', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);

        await queueOfflineSubmission({
            action: 'administration',
            url: '/api/medications/clients/1/medications/2/administrations',
            payload: {
                client_request_uuid: '6634e291-7d62-4c96-a11a-bb814366d64f',
                access_token_id: 12,
                authorization_id: 13,
                api_key_id: 14,
            },
        });

        expect(storage.items()).toHaveLength(1);
    });

    it('scrubs a credential-bearing stored item without transmitting it', async () => {
        const storage = createStorage([
            queuedSubmission({
                payload: {
                    client_request_uuid: 'a92be861-e38f-4cb0-8daf-87bd65dfcae7',
                    queued_offline: true,
                    cd_witness_credential: 'legacy-secret',
                },
            }),
        ]);
        __setOfflineQueueStorageForTests(storage);

        await replayOfflineQueue();

        expect(mockedAxios).not.toHaveBeenCalled();
        expect(storage.items()).toHaveLength(0);
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('removed without being sent'),
        );
    });

    it('scrubs credential-bearing legacy local storage during migration', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        window.localStorage.setItem(
            'emar-offline-queue:v1',
            JSON.stringify([
                {
                    id: 'fa0c781c-3c06-4f10-b637-d82e39770402',
                    url: '/emar/controlled/entries',
                    payload: {
                        client_request_uuid:
                            'fa0c781c-3c06-4f10-b637-d82e39770402',
                        witness_credential: 'legacy-secret',
                    },
                },
            ]),
        );

        await __migrateLegacyEmarQueueForTests();

        expect(mockedAxios).not.toHaveBeenCalled();
        expect(storage.items()).toHaveLength(0);
        expect(window.localStorage.getItem('emar-offline-queue:v1')).toBeNull();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('credentials are never saved'),
        );
    });

    it('durably quarantines a legacy unowned item without rebinding or transmitting it', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        window.localStorage.setItem(
            'emar-offline-queue:v1',
            JSON.stringify([
                {
                    id: '9bf7d4d1-6858-49c0-a64a-66e4b0081a77',
                    url: '/emar/stock/receive',
                    payload: {
                        client_request_uuid:
                            '9bf7d4d1-6858-49c0-a64a-66e4b0081a77',
                        client_medication_id: 42,
                    },
                },
            ]),
        );

        await __migrateLegacyEmarQueueForTests();
        await replayOfflineQueue();

        expect(window.localStorage.getItem('emar-offline-queue:v1')).toBeNull();
        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0]).toMatchObject({
            id: '9bf7d4d1-6858-49c0-a64a-66e4b0081a77',
            actorId: null,
            needsAttention: true,
        });
        expect(mockedAxios).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('remains quarantined'),
        );
    });

    it('holds another workers queued item without exposing or transmitting it', async () => {
        const item = queuedSubmission();
        const storage = createStorage([item]);
        __setOfflineQueueStorageForTests(storage);
        setOfflineQueueActor(202);

        await replayOfflineQueue();

        await expect(getPendingCount()).resolves.toBe(0);
        expect(mockedAxios).not.toHaveBeenCalled();
        expect(storage.items()).toEqual([item]);
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('another signed-in worker'),
        );

        setOfflineQueueActor(101);
        mockedAxios.mockResolvedValueOnce({ data: { success: true } });
        await replayOfflineQueue();

        expect(mockedAxios).toHaveBeenCalledWith(
            expect.objectContaining({ data: item.payload }),
        );
        expect(storage.items()).toHaveLength(0);
    });

    it('warns on a queue read failure and preserves the last known pending state', async () => {
        const storage = createStorage([queuedSubmission()]);
        __setOfflineQueueStorageForTests(storage);

        await expect(getPendingCount()).resolves.toBe(1);
        storage.list
            .mockRejectedValueOnce(new Error('IndexedDB read failed'))
            .mockRejectedValueOnce(new Error('IndexedDB read failed again'));

        await expect(getPendingCount()).resolves.toBe(1);
        await expect(getPendingCount()).resolves.toBe(1);
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('secure offline storage is unavailable'),
        );
        expect(toast.error).toHaveBeenCalledTimes(1);
    });

    it('retains an unprocessed legacy entry when safe queue migration fails', async () => {
        const storage = createStorage();
        storage.put.mockRejectedValueOnce(new Error('IndexedDB write failed'));
        __setOfflineQueueStorageForTests(storage);
        window.localStorage.setItem(
            'emar-offline-queue:v1',
            JSON.stringify([
                {
                    id: '34ece0ef-084a-4bf3-a6c3-6509d4de31cb',
                    url: '/emar/stock/receive',
                    payload: {
                        client_request_uuid:
                            '34ece0ef-084a-4bf3-a6c3-6509d4de31cb',
                        client_medication_id: 42,
                    },
                },
            ]),
        );

        await expect(__migrateLegacyEmarQueueForTests()).rejects.toBeInstanceOf(
            OfflineQueueStorageError,
        );

        expect(
            JSON.parse(
                window.localStorage.getItem('emar-offline-queue:v1') ?? '[]',
            ),
        ).toHaveLength(1);
    });

    it('generates a valid version 4 UUID when randomUUID is unavailable', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);
        window.localStorage.setItem(
            'oblivion:offline-device-id:v1',
            ' '.repeat(129),
        );
        const descriptor = Object.getOwnPropertyDescriptor(
            window.crypto,
            'randomUUID',
        );
        Object.defineProperty(window.crypto, 'randomUUID', {
            configurable: true,
            value: undefined,
        });

        try {
            await submitOffline({
                action: 'prn',
                url: '/meds/today/prn',
                payload: {
                    client_medication_id: 42,
                    client_request_uuid: 'ofq-legacy-fallback',
                },
            });

            expect(storage.items()[0].id).toMatch(UUID_V4_PATTERN);
            expect(storage.items()[0].payload.client_request_uuid).toBe(
                storage.items()[0].id,
            );
            expect(storage.items()[0].payload.origin_device_id).toEqual(
                expect.stringMatching(UUID_V4_PATTERN),
            );
        } finally {
            if (descriptor) {
                Object.defineProperty(window.crypto, 'randomUUID', descriptor);
            } else {
                delete (window.crypto as { randomUUID?: () => string })
                    .randomUUID;
            }
        }
    });

    it.each([
        '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        '2ed6657d-e927-568b-95e1-2665a8aea6a2',
    ])(
        'replaces a supplied non-v4 UUID before submission: %s',
        async (uuid) => {
            mockedAxios.mockResolvedValueOnce({ data: { success: true } });

            await submitOffline({
                action: 'prn',
                url: '/meds/today/prn',
                payload: {
                    client_medication_id: 42,
                    client_request_uuid: uuid,
                },
            });

            const sent = mockedAxios.mock.calls[0][0].data as Record<
                string,
                unknown
            >;
            expect(sent.client_request_uuid).not.toBe(uuid);
            expect(sent.client_request_uuid).toEqual(
                expect.stringMatching(UUID_V4_PATTERN),
            );
        },
    );

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
                    client_request_uuid: 'a92be861-e38f-4cb0-8daf-87bd65dfcae7',
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

    it('retains the exact request for manual retry after the eighth uncertain attempt', async () => {
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

        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0]).toMatchObject({
            id: 'a92be861-e38f-4cb0-8daf-87bd65dfcae7',
            attempts: 8,
            needsAttention: true,
        });
        expect(storage.items()[0].payload).toEqual(queuedSubmission().payload);
        expect(toast.error).toHaveBeenCalledWith(
            expect.stringContaining('same request ID'),
        );
    });

    it('manually retries a needs-attention item with the original UUID and body', async () => {
        const item = queuedSubmission({
            attempts: 8,
            needsAttention: true,
        });
        const storage = createStorage([item]);
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockResolvedValueOnce({ data: { success: true } });

        await retryOfflineSubmissionsNeedingAttention();

        expect(mockedAxios).toHaveBeenCalledWith(
            expect.objectContaining({ data: item.payload }),
        );
        expect(storage.items()).toHaveLength(0);
    });

    it('allows legacy wrappers to seed the shared queue directly', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);

        await queueOfflineSubmission({
            action: 'administration',
            url: '/api/medications/clients/1/medications/2/administrations',
            payload: {
                client_request_uuid: 'c7ca4a4d-b4f6-4335-a2ad-a554ab74d0fc',
            },
            createdAt: '2026-04-30T10:00:00.000Z',
        });

        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0]).toMatchObject({
            action: 'administration',
            id: 'c7ca4a4d-b4f6-4335-a2ad-a554ab74d0fc',
            createdAt: '2026-04-30T10:00:00.000Z',
        });
    });
});
