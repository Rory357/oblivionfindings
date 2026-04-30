import axios from 'axios';
import { toast } from 'sonner';

/* -------------------------------------------------------------------------- */
/*  PR 26 — Offline submission queue                                          */
/* -------------------------------------------------------------------------- */
/*
 * A small, focused offline queue for the highest-value frontline write
 * actions — initially PRN recording and progress-note creation. When a
 * worker submits while offline (or a request fails with a network-style
 * error) we persist the submission to IndexedDB, tell them it saved
 * locally, and automatically replay it through the real backend endpoint
 * when connectivity returns.
 *
 * Design notes:
 *   - IndexedDB (not localStorage) so we can carry larger payloads across
 *     sessions without bumping into the ~5MB localStorage ceiling.
 *   - Same backend endpoints as the online path — the server remains the
 *     source of truth; replays go through `axios` rather than Inertia so we
 *     don't navigate on a background retry.
 *   - Per-submission `client_request_uuid` is sent so the server can dedupe
 *     if the network ACK was lost and we end up replaying a submission that
 *     actually made it through.
 *   - This is explicitly NOT an offline-first rewrite. Only the specific
 *     actions wired through `submitOffline(...)` are queued.
 */

export type OfflineAction =
    | 'prn'
    | 'progress_note'
    | 'round_admin'
    | 'administration'
    | 'correction'
    | 'cd_loss_report'
    | 'cd_entry'
    | 'stock_update'
    | `transport_${string}`;
type HttpMethod = 'post' | 'put' | 'patch' | 'delete';

export interface OfflineSubmission {
    id: string;
    action: OfflineAction;
    method: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    createdAt: string;
    lastAttemptAt: string | null;
    attempts: number;
    lastError: string | null;
}

export interface OfflineQueueState {
    online: boolean;
    pendingCount: number;
    pendingSubmissions: OfflineSubmission[];
    syncing: boolean;
}

interface SubmitOfflineArgs {
    action: OfflineAction;
    method?: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    queuedMessage?: string;
}

export type SubmitOfflineResult =
    | { status: 'queued'; submission: OfflineSubmission }
    | { status: 'sent'; data: unknown };

interface QueueOfflineSubmissionArgs {
    action: OfflineAction;
    method?: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    createdAt?: string;
    attempts?: number;
    lastError?: string | null;
}

interface QueueStorage {
    list(): Promise<OfflineSubmission[]>;
    put(item: OfflineSubmission): Promise<void>;
    remove(id: string): Promise<void>;
}

const DB_NAME = 'oblivion-offline';
const DB_VERSION = 1;
const STORE = 'submissions';
const DEVICE_STORAGE_KEY = 'oblivion:offline-device-id:v1';

let booted = false;
let syncing = false;
let replayScheduled = false;
const subscribers = new Set<(state: OfflineQueueState) => void>();
let lastBroadcast: OfflineQueueState = {
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    pendingCount: 0,
    pendingSubmissions: [],
    syncing: false,
};
let lastPendingSignature = '';
let storageOverride: QueueStorage | null = null;

/* -------------------------------------------------------------------------- */
/*  IndexedDB plumbing                                                        */
/* -------------------------------------------------------------------------- */

function canUseIdb(): boolean {
    return (
        typeof window !== 'undefined' && typeof window.indexedDB !== 'undefined'
    );
}

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        if (!canUseIdb()) {
            reject(new Error('IndexedDB unavailable'));
            return;
        }
        const req = window.indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'id' });
                store.createIndex('createdAt', 'createdAt');
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () =>
            reject(req.error ?? new Error('IndexedDB open failed'));
    });
}

async function withStore<T>(
    mode: IDBTransactionMode,
    op: (store: IDBObjectStore) => Promise<T> | T,
): Promise<T> {
    const db = await openDb();
    try {
        return await new Promise<T>((resolve, reject) => {
            const tx = db.transaction(STORE, mode);
            const store = tx.objectStore(STORE);
            let result: T;
            Promise.resolve(op(store))
                .then((r) => {
                    result = r;
                })
                .catch(reject);
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(tx.error);
            tx.onabort = () =>
                reject(tx.error ?? new Error('Transaction aborted'));
        });
    } finally {
        db.close();
    }
}

function idbRequest<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

/* -------------------------------------------------------------------------- */
/*  Device + uuid helpers                                                     */
/* -------------------------------------------------------------------------- */

function createUuid(): string {
    if (
        typeof window !== 'undefined' &&
        typeof window.crypto !== 'undefined' &&
        typeof window.crypto.randomUUID === 'function'
    ) {
        return window.crypto.randomUUID();
    }
    return `ofq-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

function getDeviceId(): string {
    if (typeof window === 'undefined' || !window.localStorage) {
        return 'oblivion-web';
    }
    const existing = window.localStorage.getItem(DEVICE_STORAGE_KEY);
    if (existing) return existing;
    const id = createUuid();
    window.localStorage.setItem(DEVICE_STORAGE_KEY, id);
    return id;
}

/* -------------------------------------------------------------------------- */
/*  Queue operations                                                          */
/* -------------------------------------------------------------------------- */

async function listQueue(): Promise<OfflineSubmission[]> {
    if (storageOverride) {
        const items = await storageOverride.list();

        return items.sort((a, b) => a.createdAt.localeCompare(b.createdAt));
    }

    if (!canUseIdb()) return [];
    try {
        return await withStore('readonly', async (store) => {
            const items = await idbRequest(store.getAll());
            return (items as OfflineSubmission[]).sort((a, b) =>
                a.createdAt.localeCompare(b.createdAt),
            );
        });
    } catch {
        return [];
    }
}

async function putQueueItem(item: OfflineSubmission): Promise<void> {
    if (storageOverride) {
        await storageOverride.put(item);
        return;
    }

    if (!canUseIdb()) return;
    await withStore('readwrite', async (store) => {
        await idbRequest(store.put(item));
    });
}

async function removeQueueItem(id: string): Promise<void> {
    if (storageOverride) {
        await storageOverride.remove(id);
        return;
    }

    if (!canUseIdb()) return;
    await withStore('readwrite', async (store) => {
        await idbRequest(store.delete(id));
    });
}

export async function queueOfflineSubmission(
    args: QueueOfflineSubmissionArgs,
): Promise<OfflineSubmission> {
    const payload = enrichPayload(args.payload, true);
    const submission: OfflineSubmission = {
        id: payload.client_request_uuid as string,
        action: args.action,
        method: args.method ?? 'post',
        url: args.url,
        payload,
        createdAt: args.createdAt ?? new Date().toISOString(),
        lastAttemptAt: null,
        attempts: args.attempts ?? 0,
        lastError: args.lastError ?? null,
    };

    await putQueueItem(submission);
    void broadcastState();

    return submission;
}

export async function getPendingCount(): Promise<number> {
    const queue = await listQueue();
    return queue.length;
}

/* -------------------------------------------------------------------------- */
/*  State broadcast                                                           */
/* -------------------------------------------------------------------------- */

async function broadcastState(): Promise<void> {
    const queue = await listQueue();
    const pendingSignature = queue.map((item) => item.id).join('|');
    const next: OfflineQueueState = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: queue.length,
        pendingSubmissions: queue,
        syncing,
    };
    if (
        next.online === lastBroadcast.online &&
        next.pendingCount === lastBroadcast.pendingCount &&
        next.syncing === lastBroadcast.syncing &&
        pendingSignature === lastPendingSignature
    ) {
        return;
    }
    lastBroadcast = next;
    lastPendingSignature = pendingSignature;
    for (const cb of subscribers) {
        try {
            cb(next);
        } catch {
            // ignore subscriber errors
        }
    }
}

export function subscribeOfflineQueue(
    cb: (state: OfflineQueueState) => void,
): () => void {
    subscribers.add(cb);
    // Initial push with the current snapshot, then refresh from IDB.
    cb(lastBroadcast);
    void broadcastState();
    return () => {
        subscribers.delete(cb);
    };
}

export function getOfflineQueueSnapshot(): OfflineQueueState {
    return lastBroadcast;
}

export function __setOfflineQueueStorageForTests(
    storage: QueueStorage | null,
): void {
    storageOverride = storage;
}

export function __resetOfflineQueueRuntimeForTests(): void {
    booted = false;
    syncing = false;
    replayScheduled = false;
    subscribers.clear();
    lastBroadcast = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: 0,
        pendingSubmissions: [],
        syncing: false,
    };
    lastPendingSignature = '';
    storageOverride = null;
}

/* -------------------------------------------------------------------------- */
/*  Submit / replay                                                           */
/* -------------------------------------------------------------------------- */

function enrichPayload(
    payload: Record<string, unknown>,
    queuedOffline: boolean,
    existingUuid?: string,
): Record<string, unknown> {
    return {
        ...payload,
        client_request_uuid:
            typeof payload.client_request_uuid === 'string'
                ? payload.client_request_uuid
                : (existingUuid ?? createUuid()),
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

function isNetworkError(error: unknown): boolean {
    if (axios.isAxiosError(error)) {
        if (!error.response) return true;
        // Treat 5xx as retryable too, since the write may or may not have landed
        // — the idempotency key lets the server dedupe if it did.
        const status = error.response.status;
        return status >= 500 && status < 600;
    }
    return false;
}

async function postMutation(
    method: HttpMethod,
    url: string,
    payload: Record<string, unknown>,
): Promise<unknown> {
    const response = await axios({
        method,
        url,
        data: payload,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        // We don't care about following redirects — a 302 after a successful
        // write is fine; axios will follow it and we'll treat anything 2xx/3xx
        // as success. 409/422 from the server are real errors.
    });
    return response.data;
}

export async function submitOffline(
    args: SubmitOfflineArgs,
): Promise<SubmitOfflineResult> {
    const {
        action,
        method = 'post',
        url,
        payload,
        queuedMessage = 'Saved on this device — will send when you\u2019re back online.',
    } = args;

    const enriched = enrichPayload(payload, false);
    const id = enriched.client_request_uuid as string;

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        const submission = await queueOfflineSubmission({
            action,
            method,
            url,
            payload: enrichPayload(payload, true, id),
        });
        toast.info(queuedMessage);
        return { status: 'queued', submission };
    }

    try {
        const data = await postMutation(method, url, enriched);
        return { status: 'sent', data };
    } catch (error) {
        if (isNetworkError(error)) {
            const submission = await queueOfflineSubmission({
                action,
                method,
                url,
                payload: enrichPayload(payload, true, id),
                attempts: 1,
                lastError:
                    error instanceof Error ? error.message : 'Network error',
            });
            toast.info(queuedMessage);
            return { status: 'queued', submission };
        }
        throw error;
    }
}

async function replayOne(
    item: OfflineSubmission,
): Promise<'sent' | 'retry' | 'failed' | 'conflict'> {
    try {
        await postMutation(item.method, item.url, item.payload);
        await removeQueueItem(item.id);
        return 'sent';
    } catch (error) {
        const next: OfflineSubmission = {
            ...item,
            attempts: item.attempts + 1,
            lastAttemptAt: new Date().toISOString(),
            lastError: error instanceof Error ? error.message : 'Unknown error',
        };

        if (isNetworkError(error)) {
            if (next.attempts >= 8) {
                await removeQueueItem(item.id);
                return 'failed';
            }

            await putQueueItem(next);
            return 'retry';
        }

        // Permanent-looking failure (4xx that isn't 409/429). Keep the item so
        // the worker can be told about it on reconnect; don't silently drop.
        if (axios.isAxiosError(error) && error.response) {
            const status = error.response.status;
            // 409 conflict is terminal: server already has a competing state.
            // Drop the queued item and surface a clear error so the worker
            // re-enters if needed.
            if (status === 409) {
                if (typeof window !== 'undefined') {
                    window.dispatchEvent(
                        new CustomEvent('emar:offline-conflict', {
                            detail: {
                                entry: item,
                                response: error.response.data,
                            },
                        }),
                    );
                }
                await removeQueueItem(item.id);
                const message =
                    typeof error.response.data?.sync?.message === 'string'
                        ? error.response.data.sync.message
                        : typeof error.response.data?.error === 'string'
                          ? error.response.data.error
                          : 'A queued item conflicted with newer server state. Please re-enter it if needed.';
                toast.error(message);
                return 'conflict';
            }
            // 422 validation means the payload isn't acceptable — there is no
            // amount of retrying that helps. Drop and notify.
            if (status === 422) {
                await removeQueueItem(item.id);
                return 'failed';
            }
        }

        // Too many attempts — drop so the queue doesn't grow unboundedly.
        if (next.attempts >= 8) {
            await removeQueueItem(item.id);
            return 'failed';
        }

        await putQueueItem(next);
        return 'retry';
    }
}

export async function replayOfflineQueue(): Promise<void> {
    if (syncing) return;
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;

    const queue = await listQueue();
    if (queue.length === 0) return;

    syncing = true;
    void broadcastState();

    let sent = 0;
    let failed = 0;
    let conflicts = 0;

    try {
        for (const item of queue) {
            const result = await replayOne(item);
            if (result === 'sent') sent += 1;
            else if (result === 'failed') failed += 1;
            else if (result === 'conflict') conflicts += 1;
            else if (result === 'retry') {
                // Stop the sweep on the first network retry — we'll try again
                // on the next online/visibility event.
                if (typeof navigator !== 'undefined' && !navigator.onLine) {
                    break;
                }
            }
        }
    } finally {
        syncing = false;
        void broadcastState();
    }

    if (sent > 0) {
        toast.success(
            sent === 1 ? 'Queued item sent.' : `${sent} queued items sent.`,
        );
    }
    if (failed > 0) {
        toast.error(
            failed === 1
                ? 'A queued item could not be saved to the server. Please re-enter it.'
                : `${failed} queued items could not be saved. Please re-enter them.`,
        );
    }
    if (conflicts > 1) {
        toast.error(
            `${conflicts} queued items conflicted with newer server state. Please re-enter them if needed.`,
        );
    }
}

function scheduleReplay(): void {
    if (replayScheduled) return;
    replayScheduled = true;
    setTimeout(() => {
        replayScheduled = false;
        void replayOfflineQueue();
    }, 250);
}

/* -------------------------------------------------------------------------- */
/*  Boot                                                                      */
/* -------------------------------------------------------------------------- */

export function bootOfflineQueue(): void {
    if (booted || typeof window === 'undefined') return;
    booted = true;

    window.addEventListener('online', () => {
        void broadcastState();
        scheduleReplay();
    });
    window.addEventListener('offline', () => {
        void broadcastState();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void broadcastState();
            scheduleReplay();
        }
    });

    // Initial sweep in case we boot with pending items from a previous
    // session and the network is already up.
    void broadcastState();
    if (typeof navigator === 'undefined' || navigator.onLine) {
        scheduleReplay();
    }
}
