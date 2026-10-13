import { router } from '@inertiajs/react';
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

/**
 * What the worker saw when they saved the action: the person, medicine,
 * outcome and time. Shown when the action waits or is refused, so a refusal
 * always names the dose (EA-046). Never sent to the server.
 */
export interface OfflineSubmissionDisplay {
    person: string;
    medicine: string;
    outcome: string;
    /** The NZ instant the dose was given or was due (ISO). */
    at: string | null;
}

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
     * The server definitively refused this action (400/403/404/409/422 or a
     * `rejected` sync envelope). It was NOT recorded. The item stays on this
     * device, out of automatic and manual replay, until the worker confirms
     * they recorded it on paper or told their lead (EA-049, EA-050).
     */
    rejected?: boolean;
    /** The HTTP status of the refusal (200 for a refused sync envelope). */
    refusedStatus?: number | null;
    /** The field the refusal named, e.g. queued_offline for "too old". */
    refusedField?: string | null;
    /** When the refusal reached the server as a lead follow-up. */
    reportedAt?: string | null;
    /** The server answered 401/419: sending waits until the worker signs in again. */
    signInRequired?: boolean;
    display?: OfflineSubmissionDisplay | null;
    /** Who saved it, so others see "saved on this device by Mere" — and nothing else. */
    actorName?: string | null;
}

/**
 * Another worker's saved action on this device (EA-048). Shown only as a
 * count and who saved it (review P0, 10 Oct): never the person, medicine,
 * outcome or time. The order and due time only match a Meds today row, so
 * the dose can't be recorded twice.
 */
export interface StrandedSubmission {
    id: string;
    createdAt: string;
    actorName: string | null;
    orderId: number | null;
    dueAt: string | null;
}

export interface OfflineQueueState {
    online: boolean;
    pendingCount: number;
    needsAttentionCount: number;
    pendingSubmissions: OfflineSubmission[];
    rejectedCount: number;
    rejectedSubmissions: OfflineSubmission[];
    syncing: boolean;
    /** Sending stopped: the server asked the worker to sign in again (401/419). */
    signInRequired?: boolean;
    strandedSubmissions?: StrandedSubmission[];
}

interface SubmitOfflineArgs {
    action: OfflineAction;
    method?: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    queuedMessage?: string;
    /** Said when the server didn't answer while the device is online (EA-010). */
    unansweredMessage?: string;
    display?: OfflineSubmissionDisplay | null;
}

/**
 * `sent` says whether the request left the browser (EA-149): an action that
 * was never sent was not saved anywhere, and must not be described as
 * "may already have been saved".
 */
export type SubmitOfflineResult =
    | {
          status: 'queued';
          submission: OfflineSubmission;
          /** offline = saved before sending; unanswered = the server didn't answer. */
          reason: 'offline' | 'unanswered';
      }
    | { status: 'sent'; data: unknown }
    | {
          status: 'requires_connection';
          clientRequestUuid: string;
          message: string;
          sent: boolean;
      }
    | {
          status: 'requires_authentication';
          clientRequestUuid: string;
          message: string;
          sent: boolean;
      }
    | {
          status: 'storage_unavailable';
          clientRequestUuid: string;
          message: string;
          sent: boolean;
      };

interface QueueOfflineSubmissionArgs {
    action: OfflineAction;
    method?: HttpMethod;
    url: string;
    payload: Record<string, unknown>;
    createdAt?: string;
    attempts?: number;
    lastError?: string | null;
    display?: OfflineSubmissionDisplay | null;
}

/** Dose recordings: reported to a lead when refused. */
const DOSE_ACTIONS: ReadonlySet<OfflineAction> = new Set([
    'prn',
    'administration',
    'round_admin',
]);
/**
 * Every queued medication action is bound to the worker who saved it
 * (EA-047; review item 5): doses, controlled register entries, balance
 * checks, destructions, loss reports, stock updates, corrections and
 * follow-ups. The server refuses it from anyone else's session.
 */
const ACTOR_BOUND_ACTIONS: ReadonlySet<OfflineAction> = new Set([
    ...DOSE_ACTIONS,
    'correction',
    'medication_followup',
    'support_consent',
    'cd_loss_report',
    'cd_entry',
    'cd_balance_check',
    'cd_destruction',
    'stock_update',
]);
/** Review P1: every replay says when the device saved it; the server ages it by that. */
const REPLAY_HEADER = 'X-Medication-Replay';
const MAX_AGE_STORAGE_KEY = 'oblivion:offline-max-hours:v1';
const DEFAULT_MAX_AGE_HOURS = 12;
const OFFLINE_REVIEW_URL = '/meds/today/offline-review';
/** A request that hangs is treated like no answer, so it is retried (EA-010). */
export const MEDICATION_REQUEST_TIMEOUT_MS = 30_000;
/** Online retries after the server didn't answer: 15 s, 30 s, 1 min, 2 min, then every 5 min. */
const ONLINE_RETRY_DELAYS_MS = [15_000, 30_000, 60_000, 120_000, 300_000];
const ACTOR_STORAGE_KEY = 'oblivion:offline-actor:v1';

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
    'A saved action belongs to another worker and was not sent. It sends when they sign in on this device; if they can’t, tell your lead.';
const STALE_SESSION_MESSAGE =
    'Someone else signed in on this device. Your saved medication records were not sent. Reload the page and sign in again to send them.';
const SIGN_IN_AGAIN_MESSAGE =
    'Sign in again to send the medication records saved on this device. They have not reached the chart yet.';
const UNANSWERED_MESSAGE =
    'The server didn’t answer. Saved on this device — we’ll keep trying. Don’t record it again.';
const SOMEONE_ELSE_RECORDED_MESSAGE =
    'Someone else recorded this dose while you were offline — a lead must review it.';
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
let retryTimer: ReturnType<typeof setTimeout> | null = null;
/** The server answered 401/419; nothing is resent until the actor is set again. */
let signInRequired = false;
const subscribers = new Set<(state: OfflineQueueState) => void>();
let lastBroadcast: OfflineQueueState = {
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    pendingCount: 0,
    needsAttentionCount: 0,
    pendingSubmissions: [],
    rejectedCount: 0,
    rejectedSubmissions: [],
    syncing: false,
    signInRequired: false,
    strandedSubmissions: [],
};
let lastStranded: StrandedSubmission[] = [];
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

    const previousActorId = currentActorId;
    currentActorId = nextActorId;
    signInRequired = false;
    rememberActorAcrossTabs(nextActorId);
    if (typeof window !== 'undefined')
        window.dispatchEvent(new Event('emar:actor-changed'));
    // EA-129: the previous worker signed out or another signed in. Clear the
    // medication data they left in this browser; their unsent doses stay.
    if (previousActorId !== null)
        void purgeMedicationDeviceData(previousActorId);
    lastKnownSafeQueue = [];
    lastStranded = [];
    lastBroadcast = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: 0,
        needsAttentionCount: 0,
        pendingSubmissions: [],
        rejectedCount: 0,
        rejectedSubmissions: [],
        syncing,
        signInRequired: false,
        strandedSubmissions: [],
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

/**
 * EA-047: every tab learns which worker is signed in. A tab still showing an
 * earlier worker stops sending at once, because the browser's session now
 * belongs to someone else.
 */
function rememberActorAcrossTabs(actorId: string | null): void {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(ACTOR_STORAGE_KEY, actorId ?? '');
    } catch {
        // Storage blocked: the server still refuses another worker's items.
    }
}

function onActorChangedInAnotherTab(event: StorageEvent): void {
    if (event.key !== ACTOR_STORAGE_KEY) return;
    const signedIn = normalizeActorId(event.newValue);
    if (signedIn === currentActorId || currentActorId === null) return;
    notifyQueueWarningOnce(
        `stale-session:${currentActorId}`,
        STALE_SESSION_MESSAGE,
    );
    setOfflineQueueActor(null);
}

const FOLLOWUP_DRAFT_PREFIX = 'medication-followup-draft:';

async function readAllQueueItems(): Promise<OfflineSubmission[]> {
    if (storageOverride) return storageOverride.list();
    if (!canUseIdb()) return [];
    return withStore('readonly', async (store) => {
        const stored = await idbRequest(store.getAll());

        return stored as OfflineSubmission[];
    });
}

/**
 * EA-007 / EA-129: remove the medication data a worker leaves in this
 * browser when they sign out or another worker signs in:
 *  - every Cache Storage cache (the old service worker kept MAR pages,
 *    controlled-register PDFs and exports there);
 *  - their medication follow-up drafts;
 *  - their refused dose records that already reached a lead.
 * Doses not yet sent, and refusals no lead has, stay: they are the only
 * record of a dose that was given.
 */
export async function purgeMedicationDeviceData(
    actorId: string | null = currentActorId,
): Promise<void> {
    try {
        if (typeof caches !== 'undefined') {
            const keys = await caches.keys();
            await Promise.all(keys.map((key) => caches.delete(key)));
        }
    } catch {
        // Cache Storage unavailable: nothing was cached there.
    }

    if (typeof window !== 'undefined') {
        try {
            const owned = (key: string) =>
                key.startsWith(
                    `${FOLLOWUP_DRAFT_PREFIX}${actorId ?? 'unknown'}:`,
                ) || key.startsWith(`${FOLLOWUP_DRAFT_PREFIX}unknown:`);
            for (
                let index = window.localStorage.length - 1;
                index >= 0;
                index -= 1
            ) {
                const key = window.localStorage.key(index);
                if (key && owned(key)) window.localStorage.removeItem(key);
            }
        } catch {
            // Storage blocked: there are no drafts to remove.
        }
    }

    if (actorId === null) return;
    try {
        for (const item of await readAllQueueItems()) {
            if (
                normalizeActorId(item.actorId) === actorId &&
                item.rejected &&
                item.reportedAt
            ) {
                await removeQueueItem(item.id);
            }
        }
    } catch {
        // Secure storage unavailable: nothing more can be read or removed.
    }
}

/**
 * EA-048: before signing out, say when medication records are saved only on
 * this device. Returns true when signing out may go ahead now; otherwise the
 * app asks first (the banner shows the question) and signs out on confirm.
 */
export function guardMedicationSignOut(event?: {
    preventDefault: () => void;
}): boolean {
    const pending = lastBroadcast.pendingCount;
    if (pending === 0 || currentActorId === null) {
        void purgeMedicationDeviceData(currentActorId);
        clearMedicationHistory();
        return true;
    }
    event?.preventDefault();
    if (typeof window !== 'undefined')
        window.dispatchEvent(
            new CustomEvent('emar:sign-out-blocked', { detail: { pending } }),
        );
    return false;
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
    const stranded: StrandedSubmission[] = [];
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
            // EA-048: shown to whoever is signed in (what, for whom, when),
            // never sent under their name.
            if (!item.rejected)
                stranded.push({
                    id: item.id,
                    createdAt: item.createdAt,
                    actorName: item.actorName ?? null,
                    orderId: Number.isFinite(
                        Number(item.payload.client_medication_id),
                    )
                        ? Number(item.payload.client_medication_id)
                        : null,
                    dueAt:
                        typeof item.payload.scheduled_for === 'string'
                            ? item.payload.scheduled_for
                            : null,
                });
            continue;
        }

        safeItems.push(item);
    }

    if (discardedCredentials > 0) {
        toast.error(STORED_CREDENTIAL_REENTRY_MESSAGE);
    }

    lastStranded = stranded.sort((a, b) =>
        a.createdAt.localeCompare(b.createdAt),
    );
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
        display: args.display ?? null,
        actorName: actorNames.get(currentActorId) ?? null,
    };

    await putQueueItem(submission);
    void broadcastState();

    return submission;
}

export async function queueOfflineSubmission(
    args: QueueOfflineSubmissionArgs,
): Promise<OfflineSubmission> {
    return persistOfflineSubmission(
        args,
        enrichPayload(args.payload, true, undefined, args.action),
    );
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

/** How the worker dealt with a refused dose before removing it from the device. */
export type RefusalAcknowledgement = 'recorded_on_paper' | 'told_lead';

/**
 * Remove ONE definitively refused item belonging to the current actor, once
 * the worker has read why it was not recorded. A refused dose also needs the
 * worker to say it is on paper or their lead knows (EA-046, EA-049): there is
 * no "dismiss all", and nothing else is ever removed this way.
 */
export async function dismissRejectedOfflineSubmission(
    id: string,
    acknowledgement?: RefusalAcknowledgement,
): Promise<boolean> {
    const item = (await listQueue()).find(
        (entry) => entry.id === id && entry.rejected,
    );
    const acknowledged =
        acknowledgement === 'recorded_on_paper' ||
        acknowledgement === 'told_lead';
    if (!item || (DOSE_ACTIONS.has(item.action) && !acknowledged)) {
        await broadcastState();
        return false;
    }
    await removeQueueItem(item.id);
    await broadcastState();

    return true;
}

/* -------------------------------------------------------------------------- */
/*  State broadcast                                                           */
/* -------------------------------------------------------------------------- */

async function broadcastState(): Promise<void> {
    const queue = await listQueue();
    const pendingSignature = [
        ...queue.map(
            (item) =>
                `${item.id}:${item.rejected ? 'rejected' : item.needsAttention ? 'attention' : 'pending'}:${item.attempts}:${item.reportedAt ?? ''}`,
        ),
        `stranded:${lastStranded.map((item) => item.id).join(',')}`,
        `signin:${signInRequired}`,
    ].join('|');
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
        signInRequired: signInRequired && pending.length > 0,
        strandedSubmissions: lastStranded,
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
    if (retryTimer) clearTimeout(retryTimer);
    retryTimer = null;
    signInRequired = false;
    subscribers.clear();
    lastBroadcast = {
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        pendingCount: 0,
        needsAttentionCount: 0,
        pendingSubmissions: [],
        rejectedCount: 0,
        rejectedSubmissions: [],
        syncing: false,
        signInRequired: false,
        strandedSubmissions: [],
    };
    lastPendingSignature = '';
    storageOverride = null;
    currentActorId = null;
    notifiedQueueWarnings.clear();
    lastKnownSafeQueue = [];
    lastStranded = [];
    actorNames.clear();
}

/* -------------------------------------------------------------------------- */
/*  Submit / replay                                                           */
/* -------------------------------------------------------------------------- */

function enrichPayload(
    payload: Record<string, unknown>,
    queuedOffline: boolean,
    existingUuid?: string,
    action?: OfflineAction,
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
    // EA-047: a dose is bound to the worker who recorded it. The server
    // refuses it from anyone else's session, so a stale tab can't record it
    // under the next worker's name.
    if (
        action &&
        (ACTOR_BOUND_ACTIONS.has(action) || action.startsWith('transport_')) &&
        currentActorId !== null &&
        payload.captured_by_user_id === undefined
    ) {
        enriched.captured_by_user_id = Number(currentActorId);
    }

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
    sent: boolean,
): Extract<SubmitOfflineResult, { status: 'requires_connection' }> {
    toast.error(CREDENTIAL_RECONNECT_MESSAGE);

    return {
        status: 'requires_connection',
        clientRequestUuid,
        message: CREDENTIAL_RECONNECT_MESSAGE,
        sent,
    };
}

function requiresAuthenticationResult(
    clientRequestUuid: string,
    sent: boolean,
): Extract<SubmitOfflineResult, { status: 'requires_authentication' }> {
    toast.error(AUTHENTICATED_ACTOR_REQUIRED_MESSAGE);

    return {
        status: 'requires_authentication',
        clientRequestUuid,
        message: AUTHENTICATED_ACTOR_REQUIRED_MESSAGE,
        sent,
    };
}

function storageUnavailableResult(
    clientRequestUuid: string,
    sent: boolean,
): Extract<SubmitOfflineResult, { status: 'storage_unavailable' }> {
    toast.error(STORAGE_UNAVAILABLE_MESSAGE);

    return {
        status: 'storage_unavailable',
        clientRequestUuid,
        message: STORAGE_UNAVAILABLE_MESSAGE,
        sent,
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

/* ---------------------------- review fixes ---------------------------- */

/**
 * Review P0: another worker's saved items, as anyone signed in may see them
 * — a count and who saved them, never what or for whom.
 */
export function strandedSummary(stranded: StrandedSubmission[]): string {
    const byWorker = new Map<string, number>();
    for (const item of stranded) {
        const name = item.actorName?.trim() || '';
        byWorker.set(name, (byWorker.get(name) ?? 0) + 1);
    }
    const total = stranded.length;
    const records =
        total === 1 ? '1 medication record' : `${total} medication records`;
    if (byWorker.size === 1) {
        const [name] = [...byWorker.keys()];
        const who = name === '' ? 'another worker' : name;
        const when = name === '' ? 'that worker' : name;
        return `${records} saved on this device by ${who} — ${total === 1 ? 'it sends' : 'they send'} when ${when} signs in.`;
    }
    const names = [...byWorker.keys()].filter((name) => name !== '');
    return `${records} saved on this device by other workers${names.length ? ` (${names.join(', ')})` : ''} — each sends when its worker signs in.`;
}

/** The signed-in workers' names by id, read from the Inertia page (review P0). */
const actorNames = new Map<string | null, string>();

function readInitialPage(): unknown {
    if (typeof document === 'undefined') return null;
    const raw =
        document.getElementById('app')?.getAttribute('data-page') ?? null;
    if (!raw) return null;
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function rememberActorNameFromPage(page: unknown): void {
    const user = (
        page as {
            props?: {
                auth?: { user?: { id?: unknown; name?: unknown } | null };
            };
        } | null
    )?.props?.auth?.user;
    const id = normalizeActorId(user?.id);
    if (id !== null && typeof user?.name === 'string' && user.name !== '')
        actorNames.set(id, user.name);
}

/** Tests and callers that know the worker's name can set it directly. */
export function setOfflineQueueActorName(actorId: unknown, name: string): void {
    const id = normalizeActorId(actorId);
    if (id !== null && name !== '') actorNames.set(id, name);
}

/** Review P1: the organisation's limit, as the dialog last read it (12 h until then). */
export function rememberOfflineMaxHours(hours: unknown): void {
    if (typeof window === 'undefined') return;
    const value = Number(hours);
    if (!Number.isInteger(value) || value < 1 || value > 168) return;
    try {
        window.localStorage.setItem(MAX_AGE_STORAGE_KEY, String(value));
    } catch {
        // Unavailable storage: the default applies.
    }
}

/**
 * The limit the dialog last read, or null before any was read on this device
 * (every dose is recorded through a dialog that reads it first). The server
 * checks every replay against the organisation's setting either way.
 */
function offlineMaxHours(): number | null {
    try {
        const raw =
            typeof window === 'undefined'
                ? null
                : window.localStorage.getItem(MAX_AGE_STORAGE_KEY);
        const stored = raw === null ? NaN : Number(raw);
        return Number.isInteger(stored) && stored >= 1 ? stored : null;
    } catch {
        return null;
    }
}

function savedTooLongAgo(item: OfflineSubmission): boolean {
    const hours = offlineMaxHours();
    const saved = Date.parse(item.createdAt);
    return (
        hours !== null &&
        Number.isFinite(saved) &&
        Date.now() - saved > hours * 3_600_000
    );
}

function tooOldOnDeviceMessage(): string {
    return `Too old to sync — this dose was saved on the device more than ${offlineMaxHours() ?? DEFAULT_MAX_AGE_HOURS} hours ago, so it wasn’t sent to the chart. Use reviewed paper recovery and tell your lead.`;
}

/**
 * Review item 7: signing out forgets the medication pages in this tab's
 * history, so Back can't show them (their history is encrypted).
 */
export function clearMedicationHistory(): void {
    try {
        router.clearHistory();
    } catch {
        // No Inertia router (tests, early boot): nothing to clear.
    }
}

async function postMutation(
    method: HttpMethod,
    url: string,
    payload: Record<string, unknown>,
    replayCapturedAt: string | null = null,
): Promise<unknown> {
    const response = await axios({
        method,
        url,
        data: payload,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(replayCapturedAt ? { [REPLAY_HEADER]: replayCapturedAt } : {}),
        },
        // A hung request becomes "no answer", which is retried (EA-010).
        timeout: MEDICATION_REQUEST_TIMEOUT_MS,
        // axios silently follows a redirect to a 200 page, so an HTTP success
        // is NOT proof the write landed. Callers must confirm the JSON sync
        // envelope with `readServerSyncOutcome` before reporting success.
    });
    return response.data;
}

function isOnline(): boolean {
    return typeof navigator === 'undefined' || navigator.onLine;
}

/**
 * EA-010: while the device is online, retry what the server didn't answer
 * on a back-off (15 s, 30 s, 1 min, 2 min, then every 5 min) instead of
 * waiting for the tab to be hidden and shown again.
 */
function scheduleOnlineRetry(attempts: number): void {
    if (retryTimer !== null || !isOnline() || typeof window === 'undefined')
        return;
    const delay =
        ONLINE_RETRY_DELAYS_MS[
            Math.min(
                Math.max(attempts - 1, 0),
                ONLINE_RETRY_DELAYS_MS.length - 1,
            )
        ];
    retryTimer = setTimeout(() => {
        retryTimer = null;
        void replayOfflineQueue();
    }, delay);
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
        unansweredMessage = UNANSWERED_MESSAGE,
        display = null,
    } = args;

    const enriched = enrichPayload(payload, false, undefined, action);
    const id = enriched.client_request_uuid as string;

    if (!isOnline()) {
        // Nothing has left the browser on this path (EA-149).
        if (containsEphemeralCredential(enriched)) {
            return requiresConnectionResult(id, false);
        }

        let submission: OfflineSubmission;
        try {
            submission = await queueOfflineSubmission({
                action,
                method,
                url,
                payload: enrichPayload(payload, true, id, action),
                display,
            });
        } catch (error) {
            if (error instanceof OfflineQueueActorError) {
                return requiresAuthenticationResult(id, false);
            }
            if (error instanceof OfflineQueueStorageError) {
                return storageUnavailableResult(id, false);
            }
            throw error;
        }
        toast.info(queuedMessage);
        return { status: 'queued', submission, reason: 'offline' };
    }

    try {
        const data = await postMutation(method, url, enriched);
        return { status: 'sent', data };
    } catch (error) {
        if (isNetworkError(error)) {
            // The request left the browser: it may have been saved.
            if (containsEphemeralCredential(enriched)) {
                return requiresConnectionResult(id, true);
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
                        display,
                    },
                    enriched,
                );
            } catch (persistenceError) {
                if (persistenceError instanceof OfflineQueueActorError) {
                    return requiresAuthenticationResult(id, true);
                }
                if (persistenceError instanceof OfflineQueueStorageError) {
                    return storageUnavailableResult(id, true);
                }
                throw persistenceError;
            }
            // EA-010: while online this is the server not answering, not the
            // device being offline. Say so, and keep retrying from here.
            const unanswered = isOnline();
            toast.info(unanswered ? unansweredMessage : queuedMessage);
            if (unanswered) scheduleOnlineRetry(submission.attempts);
            return {
                status: 'queued',
                submission,
                reason: unanswered ? 'unanswered' : 'offline',
            };
        }
        throw error;
    }
}

const REJECTED_REPLAY_FALLBACK_MESSAGE =
    'The server refused this saved action, so it was not recorded.';
const UNCONFIRMED_REPLAY_MESSAGE =
    'The server did not confirm this saved action. Check the chart before trying again. Trying again won’t create a duplicate.';

/** Why a 403/404/400 answer means the action can never be sent as it is (EA-050, EA-130). */
function refusalMessageFor(status: number, data: unknown): string {
    const server = serverRejectionMessage(data);
    if (status === 404 || status === 410)
        return `Not recorded — the order changed or ended, or this person is no longer in your work.${server ? ` (${server})` : ''} Record it on paper and tell your lead.`;
    if (status === 403)
        return `Not recorded — you no longer have access to record this.${server ? ` (${server})` : ''} Record it on paper and tell your lead.`;

    return server ?? REJECTED_REPLAY_FALLBACK_MESSAGE;
}

/**
 * Keep a server-refused item on the device, flagged as not recorded, so the
 * worker sees it instead of it disappearing. It is never replayed again, and
 * a refused dose is reported for a lead follow-up (EA-049, EA-050).
 */
async function markRejected(
    item: OfflineSubmission,
    message: string | null,
    status: number | null = null,
    field: string | null = null,
): Promise<'rejected'> {
    const reason = message ?? REJECTED_REPLAY_FALLBACK_MESSAGE;
    const parked: OfflineSubmission = {
        ...item,
        attempts: item.attempts + 1,
        lastAttemptAt: new Date().toISOString(),
        lastError: reason,
        needsAttention: true,
        rejected: true,
        refusedStatus: status,
        refusedField: field,
        signInRequired: false,
    };
    await putQueueItem(parked);
    toast.error(
        reason.startsWith('Not recorded') ? reason : `Not recorded — ${reason}`,
    );
    await reportParkedDose(parked);

    return 'rejected';
}

function payloadString(item: OfflineSubmission, key: string): string | null {
    const value = item.payload[key];
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * Tell the server a dose saved on this device was not recorded, so a lead
 * gets a follow-up with what the device captured. Idempotent per request;
 * best-effort (an unreported item is reported again on the next sweep).
 */
async function reportParkedDose(item: OfflineSubmission): Promise<void> {
    if (!DOSE_ACTIONS.has(item.action) || item.reportedAt) return;
    const medicationId = Number(item.payload.client_medication_id);
    const capturedBy = normalizeActorId(item.actorId);
    if (!Number.isSafeInteger(medicationId) || medicationId < 1 || !capturedBy)
        return;
    if (capturedBy !== currentActorId || !isOnline()) return;
    try {
        const answer = (await postMutation('post', OFFLINE_REVIEW_URL, {
            client_request_uuid: item.id,
            client_medication_id: medicationId,
            captured_by_user_id: Number(capturedBy),
            captured_offline_at: payloadString(item, 'captured_offline_at'),
            scheduled_for: payloadString(item, 'scheduled_for'),
            administered_at: payloadString(item, 'administered_at'),
            status: payloadString(item, 'status') ?? 'given',
            http_status: item.refusedStatus ?? null,
            error_field: item.refusedField ?? null,
            reason: (item.lastError ?? REJECTED_REPLAY_FALLBACK_MESSAGE).slice(
                0,
                500,
            ),
        })) as { status?: unknown } | null;
        if (answer?.status === 'recorded') {
            // The dose is on the chart after all: nothing is left to review.
            await removeQueueItem(item.id);
            return;
        }
        if (answer?.status === 'reported') {
            await putQueueItem({
                ...item,
                reportedAt: new Date().toISOString(),
            });
        }
    } catch {
        // Kept unreported; the next sweep tries again.
    }
}

function hasValidationError(data: unknown, field: string): boolean {
    if (data === null || typeof data !== 'object') return false;
    const errors = (data as { errors?: Record<string, unknown> }).errors;
    return !!errors && typeof errors === 'object' && field in errors;
}

async function replayOne(
    item: OfflineSubmission,
): Promise<
    | 'sent'
    | 'retry'
    | 'failed'
    | 'rejected'
    | 'needs_attention'
    | 'sign_in'
    | 'stale_actor'
> {
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

    // Review P1: whatever its flag, a dose saved here longer ago than the
    // limit is not sent as new. It stops here and a lead is told.
    if (DOSE_ACTIONS.has(item.action) && savedTooLongAgo(item)) {
        return markRejected(
            item,
            tooOldOnDeviceMessage(),
            422,
            'queued_offline',
        );
    }

    try {
        const data = await postMutation(
            item.method,
            item.url,
            item.payload,
            item.createdAt,
        );
        const outcome = readServerSyncOutcome(data);
        if (outcome.kind === 'confirmed') {
            // EA-004: "already recorded" by someone else is not this dose
            // reaching the chart. Keep it for the worker and a lead.
            if (
                outcome.status === 'duplicate' &&
                (data as { replayed?: unknown }).replayed === false
            ) {
                return markRejected(item, SOMEONE_ELSE_RECORDED_MESSAGE, 409);
            }
            await removeQueueItem(item.id);
            return 'sent';
        }
        if (outcome.kind === 'rejected') {
            return markRejected(item, outcome.message, 200);
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

        if (axios.isAxiosError(error) && error.response) {
            const status = error.response.status;
            const body = error.response.data;
            // EA-050: signed out. Nothing is lost; it sends after signing in.
            if (status === 401 || status === 419) {
                signInRequired = true;
                await putQueueItem({
                    ...item,
                    lastAttemptAt: new Date().toISOString(),
                    lastError: SIGN_IN_AGAIN_MESSAGE,
                    signInRequired: true,
                });
                notifyQueueWarningOnce(
                    `sign-in:${currentActorId}`,
                    SIGN_IN_AGAIN_MESSAGE,
                );
                return 'sign_in';
            }
            // EA-047: this tab's session belongs to someone else now.
            if (
                status === 422 &&
                hasValidationError(body, 'captured_by_user_id')
            ) {
                notifyQueueWarningOnce(
                    `stale-session:${currentActorId}`,
                    STALE_SESSION_MESSAGE,
                );
                setOfflineQueueActor(null);
                return 'stale_actor';
            }
            if (status === 409) {
                if (typeof window !== 'undefined') {
                    window.dispatchEvent(
                        new CustomEvent('emar:offline-conflict', {
                            detail: {
                                entry: item,
                                response: body,
                            },
                        }),
                    );
                }

                return markRejected(
                    item,
                    serverRejectionMessage(body) ??
                        'A queued item conflicted with newer server state.',
                    status,
                );
            }
            // 422: refused (over limit, awaiting verification, competency,
            // witness, too old, validation). 400/403/404/410: it can never be
            // sent as it is (EA-050, EA-130). The action was NOT recorded, so
            // it stays visible on this device as refused, not retried.
            if (status === 422) {
                return markRejected(item, serverRejectionMessage(body), status);
            }
            if ([400, 403, 404, 410].includes(status)) {
                return markRejected(
                    item,
                    refusalMessageFor(status, body),
                    status,
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
    if (!isOnline()) return;
    if (signInRequired) return;

    const queue = await listQueue();
    if (queue.length === 0) return;

    syncing = true;
    void broadcastState();

    let sent = 0;
    let failed = 0;
    let rejected = 0;
    let needsAttention = 0;
    let retryAttempts = 0;

    try {
        for (const item of queue) {
            if (item.rejected) {
                // A refusal that hasn't reached a lead yet is reported again.
                if (!item.reportedAt) await reportParkedDose(item);
                continue;
            }
            if (item.needsAttention) {
                continue;
            }

            const result = await replayOne(item);
            if (result === 'sent') sent += 1;
            else if (result === 'failed') failed += 1;
            else if (result === 'rejected') rejected += 1;
            else if (result === 'needs_attention') needsAttention += 1;
            else if (result === 'sign_in' || result === 'stale_actor') break;
            else if (result === 'retry') {
                retryAttempts = Math.max(retryAttempts, item.attempts + 1);
                // Stop the sweep on the first network retry — we'll try again
                // on the next online/visibility event or retry timer.
                if (!isOnline()) {
                    break;
                }
            }
        }
    } finally {
        syncing = false;
        void broadcastState();
    }

    // EA-010: the server didn't answer while online; try again shortly.
    if (retryAttempts > 0) scheduleOnlineRetry(retryAttempts);

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
            `${rejected} queued items were not recorded. They stay on this device until you deal with each one.`,
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

    // Review P0: remember the signed-in worker's name for their saved items.
    rememberActorNameFromPage(readInitialPage());
    document.addEventListener('inertia:navigate', (event) =>
        rememberActorNameFromPage(
            (event as CustomEvent<{ page?: unknown }>).detail?.page,
        ),
    );

    window.addEventListener('online', () => {
        void broadcastState();
        scheduleReplay();
    });
    window.addEventListener('offline', () => {
        void broadcastState();
    });
    window.addEventListener('storage', onActorChangedInAnotherTab);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void broadcastState();
            scheduleReplay();
        }
    });

    // Initial sweep in case we boot with pending items from a previous
    // session and the network is already up.
    void broadcastState();
    if (isOnline()) {
        scheduleReplay();
    }
}
