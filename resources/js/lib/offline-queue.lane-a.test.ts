/* eMAR audit Lane A3 (EA-004, EA-010, EA-046–EA-050, EA-129–EA-131,
 * EA-149): what the device keeps, sends and says about medication records
 * saved on it. */
import { router } from '@inertiajs/react';
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

import { submitEmarMutation } from './emar-offline';
import * as queueModule from './offline-queue';
import {
    __resetOfflineQueueRuntimeForTests,
    __setOfflineQueueStorageForTests,
    dismissRejectedOfflineSubmission,
    getOfflineQueueActorId,
    getOfflineQueueSnapshot,
    guardMedicationSignOut,
    purgeMedicationDeviceData,
    rememberOfflineMaxHours,
    replayOfflineQueue,
    setOfflineQueueActor,
    submitOffline,
    subscribeOfflineQueue,
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

vi.mock('@inertiajs/react', () => ({
    router: { clearHistory: vi.fn() },
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), info: vi.fn(), success: vi.fn() },
}));

const mockedAxios = axios as unknown as Mock;

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

const UUID = 'a92be861-e38f-4cb0-8daf-87bd65dfcae7';

function queuedDose(
    overrides: Partial<OfflineSubmission> = {},
): OfflineSubmission {
    return {
        id: UUID,
        actorId: '101',
        action: 'administration',
        method: 'post',
        url: '/meds/today/record',
        payload: {
            client_request_uuid: UUID,
            client_medication_id: 123,
            scheduled_for: '2026-04-30T08:00:00+12:00',
            status: 'given',
            queued_offline: true,
            captured_offline_at: '2026-04-30T08:02:00+12:00',
            captured_by_user_id: 101,
        },
        createdAt: '2026-04-29T20:02:00.000Z',
        lastAttemptAt: null,
        attempts: 0,
        lastError: null,
        display: {
            person: 'Mary',
            medicine: 'Epilim 200mg',
            outcome: 'Given',
            at: '2026-04-29T20:00:00.000Z',
        },
        ...overrides,
    };
}

function reportCalls() {
    return mockedAxios.mock.calls.filter(
        ([config]) => config.url === '/meds/today/offline-review',
    );
}

describe('offline queue — Lane A3', () => {
    beforeEach(() => {
        __resetOfflineQueueRuntimeForTests();
        window.localStorage.clear();
        setOfflineQueueActor(101);
        setOnline(true);
        mockedAxios.mockReset();
        vi.mocked(toast.info).mockClear();
        vi.mocked(toast.success).mockClear();
        vi.mocked(toast.error).mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
        __resetOfflineQueueRuntimeForTests();
    });

    it('keeps a dose someone else recorded meanwhile as not recorded and reports it (EA-004)', async () => {
        const storage = createStorage([queuedDose()]);
        __setOfflineQueueStorageForTests(storage);
        mockedAxios
            .mockResolvedValueOnce({
                data: {
                    replayed: false,
                    duplicate_of: { by: 'Worker B', status: 'given' },
                    sync: { status: 'duplicate', message: 'Already recorded' },
                },
            })
            .mockResolvedValueOnce({ data: { status: 'reported' } });

        await replayOfflineQueue();

        expect(storage.items()).toHaveLength(1);
        expect(storage.items()[0]).toMatchObject({
            rejected: true,
            refusedStatus: 409,
            lastError:
                'Someone else recorded this dose while you were offline — a lead must review it.',
        });
        expect(storage.items()[0].reportedAt).toEqual(expect.any(String));
        expect(toast.success).not.toHaveBeenCalled();
        expect(reportCalls()).toHaveLength(1);
        expect(reportCalls()[0][0].data).toMatchObject({
            client_request_uuid: UUID,
            client_medication_id: 123,
            captured_by_user_id: 101,
            status: 'given',
            http_status: 409,
        });
    });

    it('says the server didn’t answer, not offline, and retries on its own while online (EA-010)', async () => {
        vi.useFakeTimers();
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'Request failed with status code 503',
            response: { status: 503, data: {} },
        });

        const result = await submitOffline({
            action: 'administration',
            url: '/meds/today/record',
            payload: { client_medication_id: 123, status: 'given' },
            queuedMessage:
                'Saved on this device — it will send when you reconnect.',
        });

        expect(result).toMatchObject({
            status: 'queued',
            reason: 'unanswered',
        });
        expect(toast.info).toHaveBeenCalledWith(
            expect.stringContaining('The server didn’t answer'),
        );
        expect(mockedAxios).toHaveBeenCalledWith(
            expect.objectContaining({ timeout: 30_000 }),
        );

        mockedAxios.mockResolvedValueOnce({
            data: { sync: { status: 'processed' } },
        });
        await vi.advanceTimersByTimeAsync(15_000);
        expect(mockedAxios).toHaveBeenCalledTimes(2);
        expect(storage.items()).toHaveLength(0);
    });

    it('binds a dose to its worker and stops a stale tab sending it under someone else (EA-047)', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);
        await submitOffline({
            action: 'administration',
            url: '/meds/today/record',
            payload: { client_medication_id: 123, status: 'given' },
        });
        expect(storage.items()[0].payload.captured_by_user_id).toBe(101);

        // Another tab signs in as someone else: this tab stops at once.
        queueModule.bootOfflineQueue();
        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'oblivion:offline-actor:v1',
                newValue: '202',
            }),
        );
        expect(getOfflineQueueActorId()).toBeNull();
    });

    it('binds every queued medication action to its worker, not only doses (EA-047, review item 5)', async () => {
        const storage = createStorage();
        __setOfflineQueueStorageForTests(storage);
        setOnline(false);
        for (const [action, url] of [
            ['cd_entry', '/emar/controlled/entries'],
            ['cd_balance_check', '/emar/controlled/balance-check'],
            ['stock_update', '/emar/stock/adjust'],
        ] as const) {
            await submitOffline({
                action,
                url,
                payload: { client_medication_id: 123 },
            });
        }

        expect(
            storage.items().map((item) => item.payload.captured_by_user_id),
        ).toEqual([101, 101, 101]);
    });
    it('treats the server refusing another worker’s item as a stale session, not a refusal (EA-047)', async () => {
        const storage = createStorage([queuedDose()]);
        __setOfflineQueueStorageForTests(storage);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        captured_by_user_id: ['Saved by another worker.'],
                    },
                },
            },
        });

        await replayOfflineQueue();

        expect(storage.items()[0].rejected).toBeUndefined();
        expect(getOfflineQueueActorId()).toBeNull();
        expect(reportCalls()).toHaveLength(0);
    });

    it.each([
        [403, /no longer have access/],
        [404, /order changed or ended/],
    ])(
        'parks a %s refusal as not recorded with its reason, and reports it (EA-050, EA-130)',
        async (status, words) => {
            const storage = createStorage([queuedDose()]);
            __setOfflineQueueStorageForTests(storage);
            mockedAxios.mockRejectedValueOnce({
                isAxiosError: true,
                response: { status, data: { message: 'Not found.' } },
            });

            await replayOfflineQueue();

            expect(storage.items()[0]).toMatchObject({
                rejected: true,
                refusedStatus: status,
                lastError: expect.stringMatching(words),
            });
            expect(reportCalls()).toHaveLength(1);
            // Never retried as "needs attention".
            mockedAxios.mockClear();
            await replayOfflineQueue();
            expect(
                mockedAxios.mock.calls.filter(
                    ([config]) => config.url === '/meds/today/record',
                ),
            ).toHaveLength(0);
        },
    );

    it.each([[401], [419]])(
        'waits for the worker to sign in again after a %s, keeping the dose pending (EA-050)',
        async (status) => {
            const storage = createStorage([
                queuedDose(),
                queuedDose({
                    id: 'second',
                    payload: {
                        ...queuedDose().payload,
                        client_request_uuid: 'second',
                    },
                }),
            ]);
            __setOfflineQueueStorageForTests(storage);
            mockedAxios.mockRejectedValueOnce({
                isAxiosError: true,
                response: { status, data: {} },
            });
            const states: boolean[] = [];
            subscribeOfflineQueue((state) =>
                states.push(!!state.signInRequired),
            );

            await replayOfflineQueue();

            expect(mockedAxios).toHaveBeenCalledTimes(1);
            expect(storage.items().every((item) => !item.rejected)).toBe(true);
            expect(storage.items()[0].needsAttention).toBeFalsy();
            await vi.waitFor(() => expect(states.at(-1)).toBe(true));
            // Nothing is resent until the worker signs in again.
            await replayOfflineQueue();
            expect(mockedAxios).toHaveBeenCalledTimes(1);
        },
    );

    it('removes a refused dose only one at a time and only once the worker says it is dealt with (EA-046, EA-049)', async () => {
        const first = queuedDose({
            rejected: true,
            lastError: 'INTERVAL NOT ELAPSED',
        });
        const second = queuedDose({ id: 'second', rejected: true });
        const storage = createStorage([first, second]);
        __setOfflineQueueStorageForTests(storage);

        expect(await dismissRejectedOfflineSubmission(first.id)).toBe(false);
        expect(storage.items()).toHaveLength(2);
        expect(
            await dismissRejectedOfflineSubmission(first.id, 'told_lead'),
        ).toBe(true);
        expect(storage.items().map((item) => item.id)).toEqual(['second']);
        expect(
            (queueModule as Record<string, unknown>)
                .dismissRejectedOfflineSubmissions,
        ).toBeUndefined();
    });

    it('says a dose that never left the browser was not sent (EA-149)', async () => {
        __setOfflineQueueStorageForTests(createStorage());
        setOnline(false);
        await expect(
            submitEmarMutation(
                '/meds/today/record',
                {
                    client_medication_id: 123,
                    witnessed_by: 9,
                    witness_credential: '123456',
                },
                { allowQueueWhenOffline: false },
            ),
        ).resolves.toMatchObject({
            status: 'requires_connection',
            sent: false,
        });
        await expect(
            submitOffline({
                action: 'administration',
                url: '/meds/today/record',
                payload: {
                    client_medication_id: 123,
                    witness_credential: '123456',
                },
            }),
        ).resolves.toMatchObject({
            status: 'requires_connection',
            sent: false,
        });

        setOnline(true);
        mockedAxios.mockRejectedValueOnce({
            isAxiosError: true,
            message: 'timeout of 30000ms exceeded',
        });
        await expect(
            submitEmarMutation(
                '/meds/today/record',
                {
                    client_medication_id: 123,
                    witnessed_by: 9,
                    witness_credential: '123456',
                },
                { allowQueueWhenOffline: false },
            ),
        ).resolves.toMatchObject({ status: 'requires_connection', sent: true });
    });

    it('lists another worker’s saved doses by who and which row, never what (EA-048, review P0)', async () => {
        const theirs = {
            ...queuedDose({ id: 'theirs', actorId: '202' }),
            actorName: 'Mere Tane',
        };
        __setOfflineQueueStorageForTests(createStorage([theirs]));
        const seen: unknown[][] = [];
        subscribeOfflineQueue((state) =>
            seen.push(state.strandedSubmissions ?? []),
        );

        await replayOfflineQueue();

        await vi.waitFor(() => expect(seen.at(-1)).toHaveLength(1));
        const [stranded] = seen.at(-1) as Record<string, unknown>[];
        expect(stranded).toEqual({
            id: 'theirs',
            createdAt: theirs.createdAt,
            actorName: 'Mere Tane',
            orderId: Number(theirs.payload.client_medication_id),
            dueAt: theirs.payload.scheduled_for ?? null,
        });
        expect(JSON.stringify(stranded)).not.toContain('Epilim');
        expect(JSON.stringify(stranded)).not.toContain('Mary');
        expect(mockedAxios).not.toHaveBeenCalled();
    });

    it('stops and reports a dose saved longer ago than the limit, whatever its flag (review P1)', async () => {
        rememberOfflineMaxHours(12);
        const base = queuedDose({ id: 'old-online' });
        const old = {
            ...base,
            createdAt: new Date(Date.now() - 13 * 3_600_000).toISOString(),
            payload: { ...base.payload, queued_offline: false },
        };
        __setOfflineQueueStorageForTests(createStorage([old]));
        mockedAxios.mockResolvedValue({ data: { status: 'reported' } });

        await replayOfflineQueue();

        const urls = mockedAxios.mock.calls.map(
            ([config]) => (config as { url: string }).url,
        );
        expect(urls).not.toContain(old.url);
        expect(urls).toContain('/meds/today/offline-review');
        const report = mockedAxios.mock.calls.find(
            ([config]) =>
                (config as { url: string }).url ===
                '/meds/today/offline-review',
        )?.[0] as { data: Record<string, unknown> };
        expect(report.data.error_field).toBe('queued_offline');
    });

    it('marks every replay with when the device saved it (review P1)', async () => {
        const fresh = queuedDose({ id: 'fresh' });
        __setOfflineQueueStorageForTests(createStorage([fresh]));
        mockedAxios.mockResolvedValue({
            data: { sync: { status: 'synced', client_request_uuid: 'fresh' } },
        });

        await replayOfflineQueue();

        const send = mockedAxios.mock.calls.find(
            ([config]) => (config as { url: string }).url === fresh.url,
        )?.[0] as { headers: Record<string, string> };
        expect(send.headers['X-Medication-Replay']).toBe(fresh.createdAt);
    });

    it('asks before signing out while doses wait on this device (EA-048)', async () => {
        __setOfflineQueueStorageForTests(createStorage([queuedDose()]));
        const states: number[] = [];
        subscribeOfflineQueue((state) => states.push(state.pendingCount));
        await vi.waitFor(() =>
            expect(getOfflineQueueSnapshot().pendingCount).toBe(1),
        );
        const blocked = vi.fn();
        window.addEventListener('emar:sign-out-blocked', blocked);
        const event = { preventDefault: vi.fn() };

        expect(guardMedicationSignOut(event)).toBe(false);
        expect(event.preventDefault).toHaveBeenCalled();
        expect(blocked).toHaveBeenCalled();
        window.removeEventListener('emar:sign-out-blocked', blocked);
    });

    it('forgets the medication pages in this tab’s history at sign-out (review item 7)', () => {
        vi.mocked(router.clearHistory).mockClear();
        __setOfflineQueueStorageForTests(createStorage());

        expect(guardMedicationSignOut()).toBe(true);
        expect(router.clearHistory).toHaveBeenCalledTimes(1);
    });
    it('clears this worker’s drafts and reported refusals at sign-out, keeping unsent doses (EA-129)', async () => {
        const unsent = queuedDose({ id: 'unsent' });
        const reported = queuedDose({
            id: 'reported',
            rejected: true,
            reportedAt: '2026-04-30T00:00:00.000Z',
        });
        const unreported = queuedDose({ id: 'unreported', rejected: true });
        const storage = createStorage([unsent, reported, unreported]);
        __setOfflineQueueStorageForTests(storage);
        window.localStorage.setItem('medication-followup-draft:101:7', '{}');
        window.localStorage.setItem('medication-followup-draft:202:8', '{}');

        await purgeMedicationDeviceData('101');

        expect(
            storage
                .items()
                .map((item) => item.id)
                .sort(),
        ).toEqual(['unreported', 'unsent']);
        expect(
            window.localStorage.getItem('medication-followup-draft:101:7'),
        ).toBeNull();
        expect(
            window.localStorage.getItem('medication-followup-draft:202:8'),
        ).toBe('{}');
    });
});
