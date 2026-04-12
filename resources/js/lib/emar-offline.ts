import axios from 'axios';
import { toast } from 'sonner';

type MutationMethod = 'post' | 'put' | 'patch' | 'delete';

type SyncStatus =
    | 'processed'
    | 'synced'
    | 'duplicate'
    | 'queued'
    | 'conflict'
    | 'rejected';

type SubmitMutationOptions = {
    method?: MutationMethod;
    allowQueueWhenOffline?: boolean;
    queuedMessage?: string;
    successMessage?: string;
    duplicateMessage?: string;
};

type SubmitMutationResult<T = unknown> = {
    status: SyncStatus;
    data?: T;
};

type OfflineQueueEntry = {
    id: string;
    method: MutationMethod;
    url: string;
    payload: Record<string, unknown>;
    queued_at: string;
};

const EMAR_QUEUE_STORAGE_KEY = 'emar-offline-queue:v1';
const EMAR_DEVICE_STORAGE_KEY = 'emar-offline-device-id:v1';

let emarOfflineBooted = false;

function canUseBrowserStorage() {
    return (
        typeof window !== 'undefined' &&
        typeof window.localStorage !== 'undefined'
    );
}

function getQueue(): OfflineQueueEntry[] {
    if (!canUseBrowserStorage()) {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(EMAR_QUEUE_STORAGE_KEY);
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function saveQueue(queue: OfflineQueueEntry[]) {
    if (!canUseBrowserStorage()) {
        return;
    }

    window.localStorage.setItem(EMAR_QUEUE_STORAGE_KEY, JSON.stringify(queue));
}

function getDeviceId() {
    if (!canUseBrowserStorage()) {
        return 'emar-web';
    }

    const existing = window.localStorage.getItem(EMAR_DEVICE_STORAGE_KEY);
    if (existing) {
        return existing;
    }

    const fallback = `emar-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const deviceId =
        typeof window.crypto !== 'undefined' &&
        typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID()
            : fallback;

    window.localStorage.setItem(EMAR_DEVICE_STORAGE_KEY, deviceId);

    return deviceId;
}

function createRequestUuid() {
    const fallback = `emar-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    return typeof window !== 'undefined' &&
        typeof window.crypto !== 'undefined' &&
        typeof window.crypto.randomUUID === 'function'
        ? window.crypto.randomUUID()
        : fallback;
}

function buildSyncPayload(
    payload: Record<string, unknown>,
    queuedOffline: boolean,
) {
    return {
        ...payload,
        client_request_uuid:
            typeof payload.client_request_uuid === 'string'
                ? payload.client_request_uuid
                : createRequestUuid(),
        captured_offline_at:
            typeof payload.captured_offline_at === 'string'
                ? payload.captured_offline_at
                : new Date().toISOString(),
        origin_device_id:
            typeof payload.origin_device_id === 'string'
                ? payload.origin_device_id
                : getDeviceId(),
        queued_offline: queuedOffline,
    };
}

function queueMutation(entry: OfflineQueueEntry) {
    const queue = getQueue();
    queue.push(entry);
    saveQueue(queue);
}

function isNetworkError(error: unknown) {
    return axios.isAxiosError(error) && !error.response;
}

function extractMessage(error: unknown, fallback: string) {
    if (axios.isAxiosError(error)) {
        const responseMessage =
            typeof error.response?.data?.message === 'string'
                ? error.response.data.message
                : typeof error.response?.data?.error === 'string'
                  ? error.response.data.error
                  : null;

        return responseMessage ?? fallback;
    }

    if (error instanceof Error && error.message) {
        return error.message;
    }

    return fallback;
}

async function executeMutation<T = unknown>(
    method: MutationMethod,
    url: string,
    payload: Record<string, unknown>,
) {
    const response = await axios({
        method,
        url,
        data: payload,
    });

    return response.data as T;
}

async function replayQueuedMutations() {
    if (typeof window === 'undefined' || !navigator.onLine) {
        return;
    }

    const queue = getQueue();

    if (queue.length === 0) {
        return;
    }

    const remaining: OfflineQueueEntry[] = [];
    let syncedCount = 0;

    for (const entry of queue) {
        try {
            await executeMutation(entry.method, entry.url, {
                ...entry.payload,
                queued_offline: true,
            });
            syncedCount += 1;
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                window.dispatchEvent(
                    new CustomEvent('emar:offline-conflict', {
                        detail: {
                            entry,
                            response: error.response.data,
                        },
                    }),
                );

                toast.error(
                    typeof error.response?.data?.sync?.message === 'string'
                        ? error.response.data.sync.message
                        : 'A queued eMAR action conflicted with newer server state and needs supervisor review.',
                );
                continue;
            }

            if (
                isNetworkError(error) ||
                (axios.isAxiosError(error) &&
                    (error.response?.status ?? 0) >= 500)
            ) {
                remaining.push(entry);
                continue;
            }

            toast.error(
                extractMessage(
                    error,
                    'A queued eMAR action could not be synced.',
                ),
            );
        }
    }

    saveQueue(remaining);

    if (syncedCount > 0) {
        toast.success(
            syncedCount === 1
                ? 'Queued eMAR action synced successfully.'
                : `${syncedCount} queued eMAR actions synced successfully.`,
        );
    }
}

function registerServiceWorker() {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}

export function bootEmarOffline() {
    if (emarOfflineBooted || typeof window === 'undefined') {
        return;
    }

    emarOfflineBooted = true;
    registerServiceWorker();

    window.addEventListener('online', () => {
        void replayQueuedMutations();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void replayQueuedMutations();
        }
    });

    if (navigator.onLine) {
        void replayQueuedMutations();
    }
}

export async function submitEmarMutation<T = unknown>(
    url: string,
    payload: Record<string, unknown>,
    options: SubmitMutationOptions = {},
): Promise<SubmitMutationResult<T>> {
    const {
        method = 'post',
        allowQueueWhenOffline = true,
        queuedMessage = 'Action saved offline and queued to sync when the device reconnects.',
        successMessage,
        duplicateMessage,
    } = options;

    const onlinePayload = buildSyncPayload(payload, false);

    if (
        typeof navigator !== 'undefined' &&
        !navigator.onLine &&
        allowQueueWhenOffline
    ) {
        const queuedPayload = buildSyncPayload(onlinePayload, true);

        queueMutation({
            id: queuedPayload.client_request_uuid as string,
            method,
            url,
            payload: queuedPayload,
            queued_at: new Date().toISOString(),
        });

        toast.info(queuedMessage);

        return { status: 'queued' };
    }

    try {
        const data = await executeMutation<
            T & { sync?: { status?: SyncStatus; message?: string } }
        >(method, url, onlinePayload);

        const syncStatus = data?.sync?.status ?? 'processed';

        if (syncStatus === 'duplicate') {
            toast.info(
                duplicateMessage ??
                    data?.sync?.message ??
                    'This action was already synced.',
            );
        } else if (successMessage) {
            toast.success(successMessage);
        }

        return {
            status: syncStatus,
            data,
        };
    } catch (error) {
        if (allowQueueWhenOffline && isNetworkError(error)) {
            const queuedPayload = buildSyncPayload(onlinePayload, true);

            queueMutation({
                id: queuedPayload.client_request_uuid as string,
                method,
                url,
                payload: queuedPayload,
                queued_at: new Date().toISOString(),
            });

            toast.info(queuedMessage);

            return { status: 'queued' };
        }

        if (axios.isAxiosError(error) && error.response?.status === 409) {
            const message =
                typeof error.response.data?.sync?.message === 'string'
                    ? error.response.data.sync.message
                    : typeof error.response.data?.error === 'string'
                      ? error.response.data.error
                      : 'The medication state changed before this action could be saved.';

            toast.error(message);

            return {
                status: 'conflict',
                data: error.response.data as T,
            };
        }

        throw error;
    }
}

export function getQueuedEmarMutationCount() {
    return getQueue().length;
}
