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
    | 'medication_followup'
    | 'progress_note'
    | 'support_consent'
    | 'round_admin'
    | 'administration'
    | 'correction'
    | 'cd_loss_report'
    | 'cd_entry'
    | 'cd_balance_check'
    | 'cd_destruction'
    | 'stock_update'
    | `transport_${string}`;
type HttpMethod = 'post' | 'put' | 'patch' | 'delete';

export interface OfflineSubmission {
    id: string;
    actorId: string | null;
    action: OfflineAction;
    method: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    createdAt: string;
    lastAttemptAt: string | null;
    attempts: number;
    lastError: string | null;
    needsAttention?: boolean;
    /**
     * The server definitively refused this action (422/409 or a `rejected`
     * sync envelope). It was NOT recorded. The item stays on this device,
     * out of automatic and manual replay, until the worker dismisses it.
     */
    rejected?: boolean;
}

export interface OfflineQueueState {
    online: boolean;
    pendingCount: number;
    needsAttentionCount: number;
    pendingSubmissions: OfflineSubmission[];
    rejectedCount: number;
    rejectedSubmissions: OfflineSubmission[];
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
    | { status: 'sent'; data: unknown }
    | {
          status: 'requires_connection';
          clientRequestUuid: string;
          message: string;
      }
    | {
          status: 'requires_authentication';
          clientRequestUuid: string;
          message: string;
      }
    | {
          status: 'storage_unavailable';
          clientRequestUuid: string;
          message: string;
      };

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
const UUID_V4_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const EPHEMERAL_SECRET_PATTERN =
    /(?:^|_)(?:credential|password|pin|token|secret|authorization|auth|api_key)$/i;
const CREDENTIAL_RECONNECT_MESSAGE =
    'Stay on this screen and reconnect before retrying. Authentication and witness credentials are never saved on this device.';
const STORED_CREDENTIAL_REENTRY_MESSAGE =
    'A saved medication action contained a credential and was removed without being sent. Please re-enter it while connected.';
const AUTHENTICATED_ACTOR_REQUIRED_MESSAGE =
    'This action was not saved because the signed-in worker could not be confirmed. Stay on this screen, sign in again, and retry.';
const DIFFERENT_ACTOR_MESSAGE =
    'A saved action belongs to another signed-in worker and was not sent. Sign in as that worker to retry it.';
const LEGACY_UNBOUND_MESSAGE =
    'A saved action could not be tied to the worker who recorded it. It remains quarantined and was not sent; please re-enter it while signed in.';
const STORAGE_UNAVAILABLE_MESSAGE =
    'This action was not saved because secure offline storage is unavailable. Stay on this screen, reconnect, and retry.';

export class EphemeralCredentialQueueError extends Error {
    constructor() {
        super(CREDENTIAL_RECONNECT_MESSAGE);
        this.name = 'EphemeralCredentialQueueError';
    }
}

export class OfflineQueueStorageError extends Error {
    constructor(cause?: unknown) {
        super(STORAGE_UNAVAILABLE_MESSAGE, { cause });
        this.name = 'OfflineQueueStorageError';
    }
}

export class OfflineQueueActorError extends Error {
    constructor() {
        super(AUTHENTICATED_ACTOR_REQUIRED_MESSAGE);
        this.name = 'OfflineQueueActorError';
    }
}

let booted = false;
let syncing = false;
let replayScheduled = false;
const subscribers = new Set<(state: OfflineQueueState) => void>();
let lastBroadcast: OfflineQueueState = {
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    pendingCount: 0,
    needsAttentionCount: 0,
    pendingSubmissions: [],
    rejectedCount: 0,
    rejectedSubmissions: [],
    syncing: false,
};
let lastPendingSignature = '';
let storageOverride: QueueStorage | null = null;
let currentActorId: string | null = null;
/** Shared actor boundary for in-memory medication recovery; never infer it from a name. */
export function getOfflineQueueActorId(): string | null {
    return currentActorId;
}
const notifiedQueueWarnings = new Set<string>();
let lastKnownSafeQueue: OfflineSubmission[] = [];

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

export function createOfflineRequestUuid(): string {
    const cryptoApi =
        typeof window !== 'undefined' ? window.crypto : globalThis.crypto;
    if (typeof cryptoApi?.randomUUID === 'function') {
        const uuid = cryptoApi.randomUUID();
        if (UUID_V4_PATTERN.test(uuid)) return uuid;
    }

    const bytes = new Uint8Array(16);
    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index += 1) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function isOfflineRequestUuidV4(value: unknown): value is string {
    return typeof value === 'string' && UUID_V4_PATTERN.test(value);
}

function getDeviceId(): string {
    if (typeof window === 'undefined' || !window.localStorage) {
        return 'oblivion-web';
    }
    const existing = window.localStorage.getItem(DEVICE_STORAGE_KEY);
    if (existing && /\S/u.test(existing) && existing.length <= 128) {
        return existing;
    }
    const id = createOfflineRequestUuid();
    window.localStorage.setItem(DEVICE_STORAGE_KEY, id);
    return id;
}

function normalizeActorId(value: unknown): string | null {
    if (typeof value === 'number' && Number.isSafeInteger(value) && value > 0) {
        return String(value);
    }

    if (typeof value === 'string') {
        const normalized = value.trim();
        if (/^[1-9]\d*$/u.test(normalized)) {
            return normalized;
        }
    }

    return null;
}

/**
 * Bind the browser queue to the server-shared Inertia identity currently on
 * screen. This is shared-device containment, not an authorization decision;
 * every replay still goes through the authenticated server endpoint.
 */
export function setOfflineQueueActor(actorId: unknown): void {
    const nextActorId = normalizeActorId(actorId);
    if (nextActorId === currentActorId) return;

    currentActorId = nextActorId;
    if (typeof window !== 'undefined')
        window.dispatchEvent(new Event('emar:actor-changed'));
    lastKnownSafeQueue = [];
    lastBroadcast = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: 0,
        needsAttentionCount: 0,
        pendingSubmissions: [],
        rejectedCount: 0,
        rejectedSubmissions: [],
        syncing,
    };
    lastPendingSignature = '';
    if (booted) {
        void broadcastState();
        if (
            nextActorId !== null &&
            (typeof navigator === 'undefined' || navigator.onLine)
        ) {
            scheduleReplay();
        }
    }
}

function notifyQueueWarningOnce(key: string, message: string): void {
    if (notifiedQueueWarnings.has(key)) return;
    notifiedQueueWarnings.add(key);
    toast.error(message);
}

/* -------------------------------------------------------------------------- */
/*  Queue operations                                                          */
/* -------------------------------------------------------------------------- */

async function listQueue(): Promise<OfflineSubmission[]> {
    let items: OfflineSubmission[];

    try {
        if (storageOverride) {
            items = await storageOverride.list();
        } else {
            if (!canUseIdb()) throw new OfflineQueueStorageError();
            items = await withStore('readonly', async (store) => {
                const stored = await idbRequest(store.getAll());

                return stored as OfflineSubmission[];
            });
        }
    } catch {
        notifyQueueWarningOnce(
            'storage-read-unavailable',
            STORAGE_UNAVAILABLE_MESSAGE,
        );

        return [...lastKnownSafeQueue];
    }

    const safeItems: OfflineSubmission[] = [];
    let discardedCredentials = 0;
    for (const item of items) {
        if (containsEphemeralCredential(item.payload)) {
            discardedCredentials += 1;
            try {
                await removeQueueItem(item.id);
            } catch {
                // Fail closed: an item that could not be scrubbed is still
                // excluded from every list and replay operation.
            }
            continue;
        }

        const itemActorId = normalizeActorId(item.actorId);
        if (itemActorId === null) {
            notifyQueueWarningOnce(
                `legacy-unbound:${item.id}`,
                LEGACY_UNBOUND_MESSAGE,
            );
            continue;
        }

        if (currentActorId === null) {
            notifyQueueWarningOnce(
                `no-current-actor:${item.id}`,
                AUTHENTICATED_ACTOR_REQUIRED_MESSAGE,
            );
            continue;
        }

        if (itemActorId !== currentActorId) {
            notifyQueueWarningOnce(
                `different-actor:${currentActorId}:${item.id}`,
                DIFFERENT_ACTOR_MESSAGE,
            );
            continue;
        }

        safeItems.push(item);
    }

    if (discardedCredentials > 0) {
        toast.error(STORED_CREDENTIAL_REENTRY_MESSAGE);
    }

    lastKnownSafeQueue = safeItems.sort((a, b) =>
        a.createdAt.localeCompare(b.createdAt),
    );

    return [...lastKnownSafeQueue];
}

async function putQueueItem(item: OfflineSubmission): Promise<void> {
    if (storageOverride) {
        try {
            await storageOverride.put(item);
        } catch (error) {
            throw error instanceof OfflineQueueStorageError
                ? error
                : new OfflineQueueStorageError(error);
        }
        return;
    }

    if (!canUseIdb()) throw new OfflineQueueStorageError();
    try {
        await withStore('readwrite', async (store) => {
            await idbRequest(store.put(item));
        });
    } catch (error) {
        throw error instanceof OfflineQueueStorageError
            ? error
            : new OfflineQueueStorageError(error);
    }
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

async function persistOfflineSubmission(
    args: QueueOfflineSubmissionArgs,
    payload: Record<string, unknown>,
): Promise<OfflineSubmission> {
    if (containsEphemeralCredential(payload)) {
        throw new EphemeralCredentialQueueError();
    }
    if (currentActorId === null) {
        throw new OfflineQueueActorError();
    }

    const submission: OfflineSubmission = {
        id: payload.client_request_uuid as string,
        actorId: currentActorId,
        action: args.action,
        method: args.method ?? 'post',
        url: args.url,
        payload,
        createdAt: args.createdAt ?? new Date().toISOString(),
        lastAttemptAt: null,
        attempts: args.attempts ?? 0,
        lastError: args.lastError ?? null,
        needsAttention: false,
    };

    await putQueueItem(submission);
    void broadcastState();

    return submission;
}

export async function queueOfflineSubmission(
    args: QueueOfflineSubmissionArgs,
): Promise<OfflineSubmission> {
    return persistOfflineSubmission(args, enrichPayload(args.payload, true));
}

/**
 * Import a pre-actor-binding legacy item into durable quarantine. It must
 * never be rebound to whoever happens to be signed in during migration.
 */
export async function quarantineLegacyOfflineSubmission(
    args: QueueOfflineSubmissionArgs,
): Promise<OfflineSubmission> {
    const payload = enrichPayload(args.payload, true);
    if (containsEphemeralCredential(payload)) {
        throw new EphemeralCredentialQueueError();
    }

    const submission: OfflineSubmission = {
        id: payload.client_request_uuid as string,
        actorId: null,
        action: args.action,
        method: args.method ?? 'post',
        url: args.url,
        payload,
        createdAt: args.createdAt ?? new Date().toISOString(),
        lastAttemptAt: null,
        attempts: args.attempts ?? 0,
        lastError: args.lastError ?? null,
        needsAttention: true,
    };

    await putQueueItem(submission);
    notifyQueueWarningOnce(
        `legacy-unbound:${submission.id}`,
        LEGACY_UNBOUND_MESSAGE,
    );
    void broadcastState();

    return submission;
}

export async function getPendingCount(): Promise<number> {
    const queue = await listQueue();
    return queue.filter((item) => !item.rejected).length;
}

/**
 * Remove server-rejected items once the worker has read why they were not
 * recorded. Only items the server definitively refused are removed.
 */
/** Dismiss one definitively rejected item belonging to the current actor. */
export async function dismissRejectedOfflineSubmission(
    id: string,
): Promise<void> {
    const item = (await listQueue()).find(
        (entry) => entry.id === id && entry.rejected,
    );
    if (item) await removeQueueItem(item.id);
    await broadcastState();
}
export async function dismissRejectedOfflineSubmissions(): Promise<void> {
    const queue = await listQueue();
    for (const item of queue) {
        if (item.rejected) {
            await removeQueueItem(item.id);
        }
    }

    await broadcastState();
}

/* -------------------------------------------------------------------------- */
/*  State broadcast                                                           */
/* -------------------------------------------------------------------------- */

async function broadcastState(): Promise<void> {
    const queue = await listQueue();
    const pendingSignature = queue
        .map(
            (item) =>
                `${item.id}:${item.rejected ? 'rejected' : item.needsAttention ? 'attention' : 'pending'}:${item.attempts}`,
        )
        .join('|');
    const pending = queue.filter((item) => !item.rejected);
    const rejected = queue.filter((item) => item.rejected);
    const next: OfflineQueueState = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: pending.length,
        needsAttentionCount: pending.filter((item) => item.needsAttention)
            .length,
        pendingSubmissions: pending,
        rejectedCount: rejected.length,
        rejectedSubmissions: rejected,
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
        needsAttentionCount: 0,
        pendingSubmissions: [],
        rejectedCount: 0,
        rejectedSubmissions: [],
        syncing: false,
    };
    lastPendingSignature = '';
    storageOverride = null;
    currentActorId = null;
    notifiedQueueWarnings.clear();
    lastKnownSafeQueue = [];
}

/* -------------------------------------------------------------------------- */
/*  Submit / replay                                                           */
/* -------------------------------------------------------------------------- */

function enrichPayload(
    payload: Record<string, unknown>,
    queuedOffline: boolean,
    existingUuid?: string,
): Record<string, unknown> {
    const payloadUuid =
        typeof payload.client_request_uuid === 'string'
            ? payload.client_request_uuid
            : null;
    const clientRequestUuid = isOfflineRequestUuidV4(payloadUuid)
        ? (payloadUuid as string)
        : isOfflineRequestUuidV4(existingUuid)
          ? (existingUuid as string)
          : createOfflineRequestUuid();
    const enriched: Record<string, unknown> = {
        ...payload,
        client_request_uuid: clientRequestUuid,
        queued_offline: queuedOffline,
    };

    if (queuedOffline) {
        enriched.captured_offline_at =
            typeof payload.captured_offline_at === 'string'
                ? payload.captured_offline_at
                : new Date().toISOString();
        enriched.origin_device_id =
            typeof payload.origin_device_id === 'string'
                ? payload.origin_device_id
                : getDeviceId();
    }

    return enriched;
}

function hasNonEmptySecretValue(value: unknown): boolean {
    if (typeof value === 'string') return value.length > 0;
    if (Array.isArray(value)) return value.length > 0;
    if (value !== null && typeof value === 'object') {
        return Object.keys(value).length > 0;
    }

    return value !== null && value !== undefined;
}

function isEphemeralSecretKey(key: string): boolean {
    const normalized = key.replace(/([a-z\d])([A-Z])/g, '$1_$2');

    return EPHEMERAL_SECRET_PATTERN.test(normalized);
}

function containsEphemeralCredential(value: unknown): boolean {
    if (Array.isArray(value)) {
        return value.some(containsEphemeralCredential);
    }

    if (value === null || typeof value !== 'object') {
        return false;
    }

    return Object.entries(value).some(([key, child]) => {
        if (isEphemeralSecretKey(key) && hasNonEmptySecretValue(child)) {
            return true;
        }

        return containsEphemeralCredential(child);
    });
}

function requiresConnectionResult(
    clientRequestUuid: string,
): Extract<SubmitOfflineResult, { status: 'requires_connection' }> {
    toast.error(CREDENTIAL_RECONNECT_MESSAGE);

    return {
        status: 'requires_connection',
        clientRequestUuid,
        message: CREDENTIAL_RECONNECT_MESSAGE,
    };
}

function requiresAuthenticationResult(
    clientRequestUuid: string,
): Extract<SubmitOfflineResult, { status: 'requires_authentication' }> {
    toast.error(AUTHENTICATED_ACTOR_REQUIRED_MESSAGE);

    return {
        status: 'requires_authentication',
        clientRequestUuid,
        message: AUTHENTICATED_ACTOR_REQUIRED_MESSAGE,
    };
}

function storageUnavailableResult(
    clientRequestUuid: string,
): Extract<SubmitOfflineResult, { status: 'storage_unavailable' }> {
    toast.error(STORAGE_UNAVAILABLE_MESSAGE);

    return {
        status: 'storage_unavailable',
        clientRequestUuid,
        message: STORAGE_UNAVAILABLE_MESSAGE,
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
        // axios silently follows a redirect to a 200 page, so an HTTP success
        // is NOT proof the write landed. Callers must confirm the JSON sync
        // envelope with `readServerSyncOutcome` before reporting success.
    });
    return response.data;
}

const CONFIRMED_SYNC_STATUSES = ['processed', 'synced', 'duplicate'] as const;
const REJECTED_SYNC_STATUSES = ['rejected', 'conflict'] as const;

export type ServerSyncOutcome =
    | {
          kind: 'confirmed';
          status: (typeof CONFIRMED_SYNC_STATUSES)[number];
          message: string | null;
      }
    | {
          kind: 'rejected';
          status: (typeof REJECTED_SYNC_STATUSES)[number];
          message: string | null;
      }
    | { kind: 'unconfirmed'; message: null };

/**
 * Classify a 2xx response body. Only a JSON object carrying a known
 * `sync.status` confirms the server handled the medication action; anything
 * else (an HTML page reached by following a redirect, a bare `{success}`) is
 * unconfirmed and must never be shown as recorded.
 */
export function readServerSyncOutcome(data: unknown): ServerSyncOutcome {
    if (data === null || typeof data !== 'object' || Array.isArray(data)) {
        return { kind: 'unconfirmed', message: null };
    }

    const body = data as {
        sync?: { status?: unknown; message?: unknown };
        error?: unknown;
    };
    const status = body.sync?.status;
    const syncMessage =
        typeof body.sync?.message === 'string' ? body.sync.message : null;
    const errorMessage = typeof body.error === 'string' ? body.error : null;

    if (
        typeof status === 'string' &&
        (CONFIRMED_SYNC_STATUSES as readonly string[]).includes(status)
    ) {
        return {
            kind: 'confirmed',
            status: status as (typeof CONFIRMED_SYNC_STATUSES)[number],
            message: syncMessage,
        };
    }

    if (
        typeof status === 'string' &&
        (REJECTED_SYNC_STATUSES as readonly string[]).includes(status)
    ) {
        return {
            kind: 'rejected',
            status: status as (typeof REJECTED_SYNC_STATUSES)[number],
            message: syncMessage ?? errorMessage,
        };
    }

    return { kind: 'unconfirmed', message: null };
}

/** Best human-readable reason from a 4xx medication rejection body. */
export function serverRejectionMessage(data: unknown): string | null {
    if (data === null || typeof data !== 'object') return null;
    const body = data as {
        sync?: { message?: unknown };
        error?: unknown;
        message?: unknown;
        errors?: Record<string, unknown>;
    };
    if (typeof body.sync?.message === 'string') return body.sync.message;
    if (typeof body.error === 'string') return body.error;
    if (body.errors && typeof body.errors === 'object') {
        for (const value of Object.values(body.errors)) {
            const first = Array.isArray(value) ? value[0] : value;
            if (typeof first === 'string' && first.length > 0) return first;
        }
    }
    if (typeof body.message === 'string') return body.message;

    return null;
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
        if (containsEphemeralCredential(enriched)) {
            return requiresConnectionResult(id);
        }

        let submission: OfflineSubmission;
        try {
            submission = await queueOfflineSubmission({
                action,
                method,
                url,
                payload: enrichPayload(payload, true, id),
            });
        } catch (error) {
            if (error instanceof OfflineQueueActorError) {
                return requiresAuthenticationResult(id);
            }
            if (error instanceof OfflineQueueStorageError) {
                return storageUnavailableResult(id);
            }
            throw error;
        }
        toast.info(queuedMessage);
        return { status: 'queued', submission };
    }

    try {
        const data = await postMutation(method, url, enriched);
        return { status: 'sent', data };
    } catch (error) {
        if (isNetworkError(error)) {
            if (containsEphemeralCredential(enriched)) {
                return requiresConnectionResult(id);
            }

            // The server may have committed before the ACK was lost. Persist
            // the exact online body so a replay has the same durable request
            // fingerprint. Only submissions captured offline from the outset
            // receive queued_offline provenance.
            let submission: OfflineSubmission;
            try {
                submission = await persistOfflineSubmission(
                    {
                        action,
                        method,
                        url,
                        payload: enriched,
                        attempts: 1,
                        lastError:
                            error instanceof Error
                                ? error.message
                                : 'Network error',
                    },
                    enriched,
                );
            } catch (persistenceError) {
                if (persistenceError instanceof OfflineQueueActorError) {
                    return requiresAuthenticationResult(id);
                }
                if (persistenceError instanceof OfflineQueueStorageError) {
                    return storageUnavailableResult(id);
                }
                throw persistenceError;
            }
            toast.info(queuedMessage);
            return { status: 'queued', submission };
        }
        throw error;
    }
}

const REJECTED_REPLAY_FALLBACK_MESSAGE =
    'The server refused this saved action, so it was not recorded.';
const UNCONFIRMED_REPLAY_MESSAGE =
    'The server did not confirm this saved action. Check the chart before trying again. Trying again won’t create a duplicate.';

/**
 * Keep a server-refused item on the device, flagged as not recorded, so the
 * worker sees it instead of it disappearing. It is never replayed again.
 */
async function markRejected(
    item: OfflineSubmission,
    message: string | null,
): Promise<'rejected'> {
    const reason = message ?? REJECTED_REPLAY_FALLBACK_MESSAGE;
    await putQueueItem({
        ...item,
        attempts: item.attempts + 1,
        lastAttemptAt: new Date().toISOString(),
        lastError: reason,
        needsAttention: true,
        rejected: true,
    });
    toast.error(`Not recorded — ${reason}`);

    return 'rejected';
}

async function replayOne(
    item: OfflineSubmission,
): Promise<'sent' | 'retry' | 'failed' | 'rejected' | 'needs_attention'> {
    const itemActorId = normalizeActorId(item.actorId);
    if (itemActorId === null || itemActorId !== currentActorId) {
        notifyQueueWarningOnce(
            itemActorId === null
                ? `legacy-unbound:${item.id}`
                : `different-actor:${currentActorId ?? 'none'}:${item.id}`,
            itemActorId === null
                ? LEGACY_UNBOUND_MESSAGE
                : currentActorId === null
                  ? AUTHENTICATED_ACTOR_REQUIRED_MESSAGE
                  : DIFFERENT_ACTOR_MESSAGE,
        );
        return 'needs_attention';
    }

    if (containsEphemeralCredential(item.payload)) {
        await removeQueueItem(item.id);
        toast.error(STORED_CREDENTIAL_REENTRY_MESSAGE);
        return 'failed';
    }

    try {
        const data = await postMutation(item.method, item.url, item.payload);
        const outcome = readServerSyncOutcome(data);
        if (outcome.kind === 'confirmed') {
            await removeQueueItem(item.id);
            return 'sent';
        }
        if (outcome.kind === 'rejected') {
            return markRejected(item, outcome.message);
        }

        // A 2xx without a sync envelope (e.g. a followed redirect) is not
        // proof of a write. Keep the exact body and UUID for manual retry.
        await putQueueItem({
            ...item,
            attempts: item.attempts + 1,
            lastAttemptAt: new Date().toISOString(),
            lastError: UNCONFIRMED_REPLAY_MESSAGE,
            needsAttention: true,
        });
        return 'needs_attention';
    } catch (error) {
        const next: OfflineSubmission = {
            ...item,
            attempts: item.attempts + 1,
            lastAttemptAt: new Date().toISOString(),
            lastError: error instanceof Error ? error.message : 'Unknown error',
        };

        if (isNetworkError(error)) {
            if (next.attempts >= 8) {
                await putQueueItem({ ...next, needsAttention: true });
                return 'needs_attention';
            }

            await putQueueItem(next);
            return 'retry';
        }

        // 409 (competing server state) and 422 (refused: over limit, awaiting
        // verification, competency, witness, validation) are terminal — no
        // retry helps. The action was NOT recorded, so it stays visible on
        // this device as rejected rather than being silently deleted.
        if (axios.isAxiosError(error) && error.response) {
            const status = error.response.status;
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

                return markRejected(
                    item,
                    serverRejectionMessage(error.response.data) ??
                        'A queued item conflicted with newer server state.',
                );
            }
            if (status === 422) {
                return markRejected(
                    item,
                    serverRejectionMessage(error.response.data),
                );
            }
        }

        // An unclassified failure is not proof that the server did not commit.
        // Retain the exact UUID and request body for explicit manual retry.
        if (next.attempts >= 8) {
            await putQueueItem({ ...next, needsAttention: true });
            return 'needs_attention';
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
    let rejected = 0;
    let needsAttention = 0;

    try {
        for (const item of queue) {
            if (item.needsAttention || item.rejected) {
                continue;
            }

            const result = await replayOne(item);
            if (result === 'sent') sent += 1;
            else if (result === 'failed') failed += 1;
            else if (result === 'rejected') rejected += 1;
            else if (result === 'needs_attention') needsAttention += 1;
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
    if (rejected > 1) {
        toast.error(
            `${rejected} queued items were not recorded. They stay on this device until you dismiss them.`,
        );
    }
    if (needsAttention > 0) {
        toast.error(
            needsAttention === 1
                ? 'A queued item needs a manual retry. Check the chart before trying again. Trying again won’t create a duplicate.'
                : `${needsAttention} queued items need a manual retry. Check the chart before trying again. Trying again won’t create a duplicate.`,
        );
    }
}

export async function retryOfflineSubmissionsNeedingAttention(): Promise<void> {
    const queue = await listQueue();
    // A server-refused item is never resent: retrying cannot change a refusal
    // and would silently re-attempt a dose the server already blocked.
    const attentionItems = queue.filter(
        (item) => item.needsAttention && !item.rejected,
    );
    for (const item of attentionItems) {
        await putQueueItem({ ...item, needsAttention: false });
    }

    await broadcastState();
    await replayOfflineQueue();
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
