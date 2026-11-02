import type { ChatSelectedFile } from './chat-selected-file';
import type { ChatMention } from './types';

export class ChatError extends Error {
    constructor(
        message: string,
        public status: number,
        public code?: string,
    ) {
        super(message);
    }
}

export const isChatAccessDenied = (failure: unknown) =>
    failure instanceof ChatError &&
    [401, 403, 404, 419].includes(failure.status);

export type ChatUploadProgress = { loaded: number; total: number | null };
export type ChatRequestOptions = RequestInit & {
    onUploadProgress?: (progress: ChatUploadProgress) => void;
};

function responseError(
    status: number,
    result: {
        message?: string;
        code?: string;
        errors?: Record<string, string[]>;
    } | null,
) {
    return new ChatError(
        Object.values(result?.errors ?? {}).flat()[0] ??
            result?.message ??
            'Chat could not connect. Please try again.',
        status,
        result?.code,
    );
}

function uploadRequest<T>(
    path: string,
    options: ChatRequestOptions,
    headers: Headers,
): Promise<T> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const signal = options.signal;
        let settled = false;
        const finish = (error?: Error, result?: T) => {
            if (settled) return;
            settled = true;
            signal?.removeEventListener('abort', abort);
            xhr.upload.onprogress = null;
            if (error) reject(error);
            else resolve(result as T);
        };
        const uncertain = () =>
            new ChatError(
                'The send result is not confirmed. Retry the same message to check it safely.',
                0,
                'chat_send_uncertain',
            );
        const abort = () => {
            xhr.abort();
            finish(uncertain());
        };
        if (signal?.aborted) {
            finish(uncertain());
            return;
        }
        xhr.open(options.method ?? 'POST', path);
        xhr.responseType = 'json';
        xhr.timeout = 180000;
        headers.forEach((value, name) => xhr.setRequestHeader(name, value));
        xhr.upload.onprogress = (event) => {
            if (!settled)
                options.onUploadProgress?.({
                    loaded: event.loaded,
                    total:
                        event.lengthComputable && event.total > 0
                            ? event.total
                            : null,
                });
        };
        xhr.onload = () => {
            if (xhr.status === 204) finish();
            else if (xhr.status >= 200 && xhr.status < 300) {
                if (xhr.response === null || typeof xhr.response !== 'object')
                    finish(uncertain());
                else finish(undefined, xhr.response as T);
            } else finish(responseError(xhr.status, xhr.response));
        };
        xhr.onerror = () => finish(uncertain());
        xhr.ontimeout = () => finish(uncertain());
        xhr.onabort = () => finish(uncertain());
        signal?.addEventListener('abort', abort, { once: true });
        try {
            xhr.send(options.body as FormData);
        } catch {
            finish(uncertain());
        }
    });
}

// Session idle time counts people, not pollers: a Chat request made shortly after
// a click or key press is marked as interactive so reading Chat keeps you signed in.
let lastInteraction = 0;
if (typeof window !== 'undefined') {
    const mark = () => {
        lastInteraction = Date.now();
    };
    window.addEventListener('pointerdown', mark, {
        capture: true,
        passive: true,
    });
    window.addEventListener('keydown', mark, { capture: true, passive: true });
}

function chatRequestHeaders(options: ChatRequestOptions) {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (Date.now() - lastInteraction < 3000)
        headers.set('X-Chat-Interactive', '1');
    if (token) headers.set('X-XSRF-TOKEN', decodeURIComponent(token));
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    if (meta) headers.set('X-CSRF-TOKEN', meta);
    if (options.body && !(options.body instanceof FormData))
        headers.set('Content-Type', 'application/json');
    return headers;
}

export async function chatRequest<T>(
    path: string,
    options: ChatRequestOptions = {},
): Promise<T> {
    const headers = chatRequestHeaders(options);
    if (options.body instanceof FormData && options.onUploadProgress)
        return uploadRequest<T>(path, options, headers);
    const requestOptions = { ...options };
    delete requestOptions.onUploadProgress;
    const response = await fetch(path, {
        ...requestOptions,
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) {
        const result = (await response.json().catch(() => ({}))) as {
            message?: string;
            code?: string;
            errors?: Record<string, string[]>;
        };
        throw responseError(response.status, result);
    }
    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
}

export type ChatFileResponse = {
    blob: Blob;
    filename: string | null;
    headers: Headers;
};

/** A request answered with a file (for example a logged export); errors still arrive as JSON. */
export async function chatFileRequest(
    path: string,
    options: ChatRequestOptions = {},
): Promise<ChatFileResponse> {
    const headers = chatRequestHeaders(options);
    // JSON first, so refusals and validation errors come back as JSON.
    headers.set('Accept', 'application/json, application/pdf');
    const requestOptions = { ...options };
    delete requestOptions.onUploadProgress;
    const response = await fetch(path, {
        ...requestOptions,
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) {
        const result = (await response.json().catch(() => ({}))) as {
            message?: string;
            code?: string;
            errors?: Record<string, string[]>;
        };
        throw responseError(response.status, result);
    }
    const disposition = response.headers.get('Content-Disposition') ?? '';
    const filename = /filename="?([^";]+)"?/i.exec(disposition)?.[1] ?? null;
    return { blob: await response.blob(), filename, headers: response.headers };
}

export const conversationPath = (id: number) => `/chat/api/conversations/${id}`;

// Memory only: drafts survive navigation between Chat surfaces, never disk or another signed-in account.
const drafts = new Map<string, string>();
const pendingSends = new Map<string, FormData>();
export type PendingChatCreation = {
    body: string;
    people: { id: number; name: string }[];
};
const pendingCreations = new Map<string, PendingChatCreation>();
export type ChatThreadMemory = {
    uploads?: ChatUploadDraft[];
    fileCaptions?: string[];
    editing?: ChatEditDraft | null;
    removing?: ChatRemovalDraft | null;
    mentions: ChatMention[];
    files: ChatSelectedFile[];
    replyId: number | null;
    query: string;
    searchOpen: boolean;
    readAnchor: number | null;
    readOffset: number;
};
export type ChatUploadDraft = {
    file: ChatSelectedFile;
    id: string;
    state:
        | 'selected'
        | 'checking'
        | 'uploading'
        | 'scanning'
        | 'ready'
        | 'blocked'
        | 'unavailable'
        | 'changed'
        | 'unknown'
        | 'removing'
        | 'remove_failed'
        | 'discarded'
        | 'consumed';
    attempted: boolean;
    error: string;
    progress: ChatUploadProgress | null;
};
export type ChatRemovalDraft = {
    messageId: number;
    version: number;
    content: string;
    pendingBody?: string | null;
};
export type ChatEditDraft = {
    messageId: number;
    version: number;
    content: string;
    mentions: ChatMention[];
    pendingBody?: string | null;
};
const threadMemory = new Map<string, ChatThreadMemory>();
export type ChatCallbackPending = {
    path: string;
    method: 'POST' | 'PATCH';
    body: string;
    label: string;
};
export type ChatCallbackEdit = {
    owner: string;
    agreed: string;
    version: number;
};
export type ChatCallbackMemory = {
    preferred: string;
    note: string;
    pending: ChatCallbackPending | null;
    edits: Record<number, ChatCallbackEdit>;
};
const callbackMemory = new Map<string, ChatCallbackMemory>();
export type ChatFamilyWorkMemory = {
    owner: string;
    note: string;
    coordination: string;
    version: number | null;
    pending: string | null;
};
const familyWorkMemory = new Map<string, ChatFamilyWorkMemory>();
export type ChatFollowupMemory = {
    action: string;
    owner: string;
    due: string;
    pending: string | null;
};
const followupMemory = new Map<string, ChatFollowupMemory>();
export type ChatPublicationMemory = {
    query: string;
    client: string;
    destination: 'documents' | 'photos';
    familyVisible: boolean;
    pending: string | null;
};
const publicationMemory = new Map<string, ChatPublicationMemory>();
export function readPublicationMemory(key: string): ChatPublicationMemory {
    return (
        publicationMemory.get(key) ?? {
            query: '',
            client: '',
            destination: 'documents',
            familyVisible: false,
            pending: null,
        }
    );
}
export function savePublicationMemory(
    key: string,
    update: Partial<ChatPublicationMemory>,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    publicationMemory.set(key, { ...readPublicationMemory(key), ...update });
}
export function readFollowupMemory(key: string): ChatFollowupMemory {
    return (
        followupMemory.get(key) ?? {
            action: '',
            owner: '',
            due: '',
            pending: null,
        }
    );
}
export function saveFollowupMemory(
    key: string,
    update: Partial<ChatFollowupMemory>,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    followupMemory.set(key, { ...readFollowupMemory(key), ...update });
}
export function readFamilyWorkMemory(key: string): ChatFamilyWorkMemory {
    return (
        familyWorkMemory.get(key) ?? {
            owner: '',
            note: '',
            coordination: '',
            version: null,
            pending: null,
        }
    );
}
export function saveFamilyWorkMemory(
    key: string,
    update: Partial<ChatFamilyWorkMemory>,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    familyWorkMemory.set(key, { ...readFamilyWorkMemory(key), ...update });
}
export function readChatCallbackMemory(key: string): ChatCallbackMemory {
    return (
        callbackMemory.get(key) ?? {
            preferred: '',
            note: '',
            pending: null,
            edits: {},
        }
    );
}
export function saveChatCallbackMemory(
    key: string,
    update: Partial<ChatCallbackMemory>,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    callbackMemory.set(key, { ...readChatCallbackMemory(key), ...update });
}
let draftActor: number | null = null;
const actorCleanups = new Set<() => void>();
export function onChatActorReset(cleanup: () => void) {
    actorCleanups.add(cleanup);
    return () => {
        actorCleanups.delete(cleanup);
    };
}
export function setChatDraftActor(actor: number | null) {
    if (draftActor === actor) return;
    actorCleanups.forEach((cleanup) => cleanup());
    drafts.clear();
    pendingSends.clear();
    pendingCreations.clear();
    threadMemory.clear();
    callbackMemory.clear();
    familyWorkMemory.clear();
    followupMemory.clear();
    publicationMemory.clear();
    draftActor = actor;
}
export function chatDraftKey(
    actor: number,
    client: number | null,
    conversation: number,
) {
    setChatDraftActor(actor);
    return `${actor}:${client ?? 'staff'}:${conversation}`;
}
export function readChatDraft(key: string) {
    return drafts.get(key) ?? '';
}

export function readPendingChatCreation(key: string) {
    return pendingCreations.get(key) ?? null;
}

export function savePendingChatCreation(
    key: string,
    value: PendingChatCreation | null,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    if (value) pendingCreations.set(key, value);
    else pendingCreations.delete(key);
}
export function saveChatDraft(key: string, value: string) {
    if (!key.startsWith(`${draftActor}:`)) return;
    if (value) drafts.set(key, value);
    else drafts.delete(key);
}

export function readPendingChatSend(key: string) {
    return pendingSends.get(key) ?? null;
}
export function savePendingChatSend(key: string, body: FormData | null) {
    if (!key.startsWith(`${draftActor}:`)) return;
    if (body) pendingSends.set(key, body);
    else pendingSends.delete(key);
}

export function readChatThreadMemory(key: string): ChatThreadMemory {
    return (
        threadMemory.get(key) ?? {
            mentions: [],
            files: [],
            replyId: null,
            query: '',
            searchOpen: false,
            readAnchor: null,
            readOffset: 0,
        }
    );
}

export function saveChatThreadMemory(
    key: string,
    update: Partial<ChatThreadMemory>,
) {
    if (!key.startsWith(`${draftActor}:`)) return;
    threadMemory.set(key, { ...readChatThreadMemory(key), ...update });
}
