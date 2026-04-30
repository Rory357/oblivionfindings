import axios from 'axios';
import { toast } from 'sonner';

import {
    getOfflineQueueSnapshot,
    queueOfflineSubmission,
    submitOffline,
    type OfflineAction,
} from './offline-queue';

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
    action?: OfflineAction;
    allowQueueWhenOffline?: boolean;
    queuedMessage?: string;
    successMessage?: string;
    duplicateMessage?: string;
};

type SubmitMutationResult<T = unknown> = {
    status: SyncStatus;
    data?: T;
};

type LegacyOfflineQueueEntry = {
    id?: string;
    method?: MutationMethod;
    url?: string;
    payload?: Record<string, unknown>;
    queued_at?: string;
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

function registerServiceWorker() {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}

function inferAction(url: string): OfflineAction {
    if (url.includes('/guided/items/')) return 'round_admin';
    if (url.includes('/administrations') && url.includes('/corrections')) {
        return 'correction';
    }
    if (url.includes('/administrations')) return 'administration';
    if (url.includes('/controlled/loss-reports')) return 'cd_loss_report';
    if (url.includes('/controlled/entries')) return 'cd_entry';
    if (url.includes('/stock') || url.includes('/scheduled-counts')) {
        return 'stock_update';
    }
    if (url.includes('/fleet-assets') || url.includes('/transports')) {
        return 'transport_medication';
    }

    return 'administration';
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
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    return response.data as T;
}

async function migrateLegacyQueue() {
    if (!canUseBrowserStorage()) return;

    const raw = window.localStorage.getItem(EMAR_QUEUE_STORAGE_KEY);
    if (!raw) return;

    let entries: LegacyOfflineQueueEntry[] = [];
    try {
        const parsed = JSON.parse(raw);
        entries = Array.isArray(parsed) ? parsed : [];
    } catch {
        entries = [];
    }

    window.localStorage.removeItem(EMAR_QUEUE_STORAGE_KEY);
    window.localStorage.removeItem(EMAR_DEVICE_STORAGE_KEY);

    for (const entry of entries) {
        if (!entry.url || !entry.payload) continue;

        await queueOfflineSubmission({
            action: inferAction(entry.url),
            method: entry.method ?? 'post',
            url: entry.url,
            payload: {
                ...entry.payload,
                client_request_uuid:
                    entry.payload.client_request_uuid ?? entry.id,
                queued_offline: true,
            },
            createdAt: entry.queued_at,
        });
    }
}

export function bootEmarOffline() {
    if (emarOfflineBooted || typeof window === 'undefined') {
        return;
    }

    emarOfflineBooted = true;
    registerServiceWorker();
    void migrateLegacyQueue();
}

export async function submitEmarMutation<T = unknown>(
    url: string,
    payload: Record<string, unknown>,
    options: SubmitMutationOptions = {},
): Promise<SubmitMutationResult<T>> {
    const {
        method = 'post',
        action = inferAction(url),
        allowQueueWhenOffline = true,
        queuedMessage = 'Action saved offline and queued to sync when the device reconnects.',
        successMessage,
        duplicateMessage,
    } = options;

    try {
        if (!allowQueueWhenOffline) {
            const data = await executeMutation<
                T & { sync?: { status?: SyncStatus; message?: string } }
            >(method, url, payload);
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
        }

        const result = await submitOffline({
            action,
            method,
            url,
            payload,
            queuedMessage,
        });

        if (result.status === 'queued') {
            return { status: 'queued' };
        }

        const data = result.data as T & {
            sync?: { status?: SyncStatus; message?: string };
        };
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
    return getOfflineQueueSnapshot().pendingCount;
}
