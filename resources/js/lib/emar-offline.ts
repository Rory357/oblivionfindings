import axios from 'axios';
import { toast } from 'sonner';

import {
    createOfflineRequestUuid,
    EphemeralCredentialQueueError,
    getOfflineQueueSnapshot,
    isOfflineRequestUuidV4,
    quarantineLegacyOfflineSubmission,
    submitOffline,
    type OfflineAction,
} from './offline-queue';

type MutationMethod = 'post' | 'put' | 'patch' | 'delete';

export type SyncStatus =
    | 'processed'
    | 'synced'
    | 'duplicate'
    | 'queued'
    | 'requires_connection'
    | 'requires_authentication'
    | 'storage_unavailable'
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
    clientRequestUuid?: string;
};

export type MedicationMutationReplayState = {
    uuid: string;
    fingerprint: string | null;
};

function canonicalizeReplayMaterial(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(canonicalizeReplayMaterial);
    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .sort(([left], [right]) => left.localeCompare(right))
                .map(([key, child]) => [
                    key,
                    canonicalizeReplayMaterial(child),
                ]),
        );
    }

    return value;
}

export function createMedicationMutationReplayState(
    createUuid: () => string = createOfflineRequestUuid,
): MedicationMutationReplayState {
    return { uuid: createUuid(), fingerprint: null };
}

export function prepareMedicationMutationReplayState(
    current: MedicationMutationReplayState,
    material: Record<string, unknown>,
    createUuid: () => string = createOfflineRequestUuid,
): MedicationMutationReplayState {
    const fingerprint = JSON.stringify(canonicalizeReplayMaterial(material));

    if (current.fingerprint !== null && current.fingerprint !== fingerprint) {
        return { uuid: createUuid(), fingerprint };
    }

    return { uuid: current.uuid, fingerprint };
}

type LegacyOfflineQueueEntry = {
    id?: string;
    method?: MutationMethod;
    url?: string;
    payload?: Record<string, unknown>;
    queued_at?: string;
};

const CONNECTION_REQUIRED_MESSAGE =
    'Stay on this screen and reconnect before retrying. The same request ID has been kept.';

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

    for (const [index, entry] of entries.entries()) {
        try {
            if (entry.url && entry.payload) {
                await quarantineLegacyOfflineSubmission({
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
        } catch (error) {
            if (error instanceof EphemeralCredentialQueueError) {
                toast.error(error.message);
            } else {
                window.localStorage.setItem(
                    EMAR_QUEUE_STORAGE_KEY,
                    JSON.stringify(entries.slice(index)),
                );
                throw error;
            }
        }

        const remaining = entries.slice(index + 1);
        if (remaining.length > 0) {
            window.localStorage.setItem(
                EMAR_QUEUE_STORAGE_KEY,
                JSON.stringify(remaining),
            );
        } else {
            window.localStorage.removeItem(EMAR_QUEUE_STORAGE_KEY);
        }
    }

    if (entries.length === 0) {
        window.localStorage.removeItem(EMAR_QUEUE_STORAGE_KEY);
    }
    window.localStorage.removeItem(EMAR_DEVICE_STORAGE_KEY);
}

export async function __migrateLegacyEmarQueueForTests(): Promise<void> {
    await migrateLegacyQueue();
}

export function bootEmarOffline() {
    if (emarOfflineBooted || typeof window === 'undefined') {
        return;
    }

    emarOfflineBooted = true;
    registerServiceWorker();
    void migrateLegacyQueue().catch((error: unknown) => {
        toast.error(
            error instanceof Error
                ? error.message
                : 'Secure offline storage is unavailable. Saved actions were not changed.',
        );
    });
}

export function emarMutationWasAccepted(status: SyncStatus): boolean {
    return ['processed', 'synced', 'duplicate', 'queued'].includes(status);
}

function isUncertainTransportError(error: unknown): boolean {
    if (!axios.isAxiosError(error)) return false;
    if (!error.response) return true;

    return error.response.status >= 500 && error.response.status < 600;
}

function requiresConnectionResult<T>(
    clientRequestUuid: string,
): SubmitMutationResult<T> {
    toast.error(CONNECTION_REQUIRED_MESSAGE);

    return {
        status: 'requires_connection',
        clientRequestUuid,
    };
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
            const suppliedUuid = payload.client_request_uuid;
            const clientRequestUuid = isOfflineRequestUuidV4(suppliedUuid)
                ? suppliedUuid
                : createOfflineRequestUuid();
            const onlinePayload = {
                ...payload,
                client_request_uuid: clientRequestUuid,
            };

            if (typeof navigator !== 'undefined' && !navigator.onLine) {
                return requiresConnectionResult<T>(clientRequestUuid);
            }

            let data: T & {
                sync?: { status?: SyncStatus; message?: string };
            };
            try {
                data = await executeMutation<
                    T & { sync?: { status?: SyncStatus; message?: string } }
                >(method, url, onlinePayload);
            } catch (error) {
                if (isUncertainTransportError(error)) {
                    return requiresConnectionResult<T>(clientRequestUuid);
                }
                throw error;
            }
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

        if (result.status === 'requires_connection') {
            return {
                status: 'requires_connection',
                clientRequestUuid: result.clientRequestUuid,
            };
        }

        if (
            result.status === 'requires_authentication' ||
            result.status === 'storage_unavailable'
        ) {
            return {
                status: result.status,
                clientRequestUuid: result.clientRequestUuid,
            };
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
