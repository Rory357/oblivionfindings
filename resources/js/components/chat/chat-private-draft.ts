import { ChatError, chatRequest, conversationPath } from './api';

export type PrivateChatDraft = {
    content: string;
    reply_to_id: number | null;
    mentions: { user_id: number; start: number; length: number }[];
    uploads: { id: string; caption: string }[];
};
export type PrivateDraftScope = {
    actor_id: number;
    conversation_id: number;
    client_id: number | null;
};
export type PrivateSendBody = {
    recoverable_send: true;
    mutation_id: string;
    draft_version: number;
    draft_boundary: string;
    audience_token: string | null;
    content: string;
    reply_to_id: number | null;
    mentions: PrivateChatDraft['mentions'];
    upload_ids: string[];
    captions: string[];
};
export type PrivateSendAttempt = {
    actor_id: number;
    conversation_id: number;
    mutation_id: string;
    state: 'pending' | 'confirmed' | 'cancelled' | 'scope_changed' | 'missing';
    message_id: number | null;
    body: PrivateSendBody | null;
    can_retry: boolean;
};
export type PrivateDraftSnapshot = PrivateDraftScope & {
    boundary: string;
    version: number;
    state: 'empty' | 'saved' | 'scope_changed';
    draft: PrivateChatDraft | null;
    updated_at: string | null;
    applied_version?: number;
    pending_send: PrivateSendAttempt | null;
};
type Save = {
    mutation_id: string;
    version: number;
    boundary: string;
    draft: PrivateChatDraft | null;
};
export type PrivateDraftState = {
    phase:
        | 'loading'
        | 'ready'
        | 'saving'
        | 'uncertain'
        | 'conflict'
        | 'scope_changed'
        | 'unavailable'
        | 'revoked'
        | 'pending_send';
    local: PrivateChatDraft | null;
    remote: PrivateDraftSnapshot | null;
    dirty: boolean;
    error: string;
};
const integer = (value: unknown, minimum = 0): value is number =>
    typeof value === 'number' &&
    Number.isSafeInteger(value) &&
    value >= minimum;
const object = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);
const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const equal = (a: unknown, b: unknown) =>
    JSON.stringify(a) === JSON.stringify(b);
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

export function parsePrivateDraft(value: unknown): PrivateChatDraft | null {
    if (
        !object(value) ||
        typeof value.content !== 'string' ||
        [...value.content].length > 10000 ||
        (value.reply_to_id !== null && !integer(value.reply_to_id, 1)) ||
        !Array.isArray(value.mentions) ||
        value.mentions.length > 30 ||
        !Array.isArray(value.uploads) ||
        value.uploads.length > 5
    )
        return null;
    if (
        !value.mentions.every(
            (row: unknown) =>
                object(row) &&
                integer(row.user_id, 1) &&
                integer(row.start) &&
                row.start <= 10000 &&
                integer(row.length, 2) &&
                row.length <= 256,
        )
    )
        return null;
    if (
        !value.uploads.every(
            (row: unknown) =>
                object(row) &&
                typeof row.id === 'string' &&
                uuid.test(row.id) &&
                typeof row.caption === 'string' &&
                [...row.caption].length <= 1000,
        )
    )
        return null;
    if (
        new Set(value.uploads.map((row: { id: string }) => row.id)).size !==
        value.uploads.length
    )
        return null;
    return {
        content: value.content,
        reply_to_id: value.reply_to_id as number | null,
        mentions: value.mentions.map(({ user_id, start, length }) => ({
            user_id,
            start,
            length,
        })),
        uploads: value.uploads.map(({ id, caption }) => ({ id, caption })),
    };
}

export function parsePrivateDraftSnapshot(
    value: unknown,
    scope: PrivateDraftScope,
): PrivateDraftSnapshot | null {
    if (
        !object(value) ||
        value.actor_id !== scope.actor_id ||
        value.conversation_id !== scope.conversation_id ||
        value.client_id !== scope.client_id ||
        typeof value.boundary !== 'string' ||
        !/^[a-f0-9]{64}$/.test(value.boundary) ||
        !integer(value.version) ||
        value.version > 9007199254740000 ||
        !['empty', 'saved', 'scope_changed'].includes(value.state as string) ||
        (value.updated_at !== null && typeof value.updated_at !== 'string') ||
        (value.applied_version !== undefined &&
            (!integer(value.applied_version, 1) ||
                value.applied_version > value.version))
    )
        return null;
    const draft =
        value.state === 'saved' ? parsePrivateDraft(value.draft) : null;
    if (value.state === 'saved' ? !draft : value.draft !== null) return null;
    const pending =
        value.pending_send === null
            ? null
            : parsePrivateSendAttempt(value.pending_send, scope);
    if (
        value.pending_send !== null &&
        (!pending || !['pending', 'scope_changed'].includes(pending.state))
    )
        return null;
    if (pending?.body && pending.body.draft_boundary !== value.boundary)
        return null;
    return {
        ...scope,
        boundary: value.boundary,
        version: value.version,
        state: value.state as PrivateDraftSnapshot['state'],
        draft,
        pending_send: pending,
        updated_at: value.updated_at as string | null,
        ...(value.applied_version === undefined
            ? {}
            : { applied_version: value.applied_version as number }),
    };
}

export function parsePrivateSendAttempt(
    value: unknown,
    scope: PrivateDraftScope,
    mutation?: string,
): PrivateSendAttempt | null {
    if (
        !object(value) ||
        value.actor_id !== scope.actor_id ||
        value.conversation_id !== scope.conversation_id ||
        typeof value.mutation_id !== 'string' ||
        !uuid.test(value.mutation_id) ||
        (mutation !== undefined && mutation !== value.mutation_id) ||
        ![
            'pending',
            'confirmed',
            'cancelled',
            'scope_changed',
            'missing',
        ].includes(value.state as string) ||
        typeof value.can_retry !== 'boolean' ||
        (value.message_id !== null && !integer(value.message_id, 1))
    )
        return null;
    let body: PrivateSendBody | null = null;
    if (value.state === 'pending') {
        const source = value.body;
        if (
            value.message_id !== null ||
            !object(source) ||
            source.recoverable_send !== true ||
            source.mutation_id !== value.mutation_id ||
            !integer(source.draft_version, 1) ||
            typeof source.draft_boundary !== 'string' ||
            !/^[a-f0-9]{64}$/.test(source.draft_boundary) ||
            (source.audience_token !== null &&
                (typeof source.audience_token !== 'string' ||
                    !/^[a-f0-9]{64}$/.test(source.audience_token))) ||
            !Array.isArray(source.upload_ids) ||
            !Array.isArray(source.captions) ||
            source.upload_ids.length !== source.captions.length
        )
            return null;
        const captions = source.captions;
        const draft = parsePrivateDraft({
            content: source.content,
            reply_to_id: source.reply_to_id,
            mentions: source.mentions,
            uploads: source.upload_ids.map((id, index) => ({
                id,
                caption: captions[index],
            })),
        });
        if (!draft) return null;
        body = {
            recoverable_send: true,
            mutation_id: source.mutation_id as string,
            draft_version: source.draft_version,
            draft_boundary: source.draft_boundary,
            audience_token: source.audience_token as string | null,
            content: draft.content,
            reply_to_id: draft.reply_to_id,
            mentions: draft.mentions,
            upload_ids: draft.uploads.map((upload) => upload.id),
            captions: draft.uploads.map((upload) => upload.caption),
        };
    } else if (
        value.body !== null ||
        value.can_retry ||
        (value.state !== 'confirmed' && value.message_id !== null)
    )
        return null;
    return {
        actor_id: scope.actor_id,
        conversation_id: scope.conversation_id,
        mutation_id: value.mutation_id,
        state: value.state as PrivateSendAttempt['state'],
        body,
        message_id: value.message_id as number | null,
        can_retry: value.can_retry,
    };
}

/** One composer owner. Care content is held in memory or the protected server draft, never browser storage. */
export class PrivateChatDraftSession {
    private state: PrivateDraftState;
    private listeners = new Set<() => void>();
    private request: AbortController | null = null;
    private pending: Save | null = null;
    private disposed = false;
    private idleWaiters = new Set<() => void>();

    private requestFinished(controller: AbortController) {
        if (this.request !== controller) return;
        this.request = null;
        this.idleWaiters.forEach((resolve) => resolve());
        this.idleWaiters.clear();
    }

    /** Wait for the current save, then save newer typing once. Never retries an uncertain write implicitly. */
    async flush(): Promise<PrivateDraftSnapshot | null> {
        if (this.request)
            await new Promise<void>((resolve) => this.idleWaiters.add(resolve));
        if (this.disposed || this.state.phase !== 'ready') return null;
        if (this.state.dirty) await this.save();
        return !this.disposed &&
            this.state.phase === 'ready' &&
            !this.state.dirty
            ? this.state.remote
            : null;
    }

    constructor(
        private readonly scope: PrivateDraftScope,
        initial?: PrivateChatDraft | null,
    ) {
        this.scope = { ...scope };
        this.state = {
            phase: 'loading',
            local: copy(initial ?? null),
            remote: null,
            dirty: initial !== undefined,
            error: '',
        };
    }
    snapshot = () => this.state;
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    private publish(patch: Partial<PrivateDraftState>) {
        if (this.disposed) return;
        this.state = { ...this.state, ...patch };
        this.listeners.forEach((listener) => listener());
    }
    edit(draft: PrivateChatDraft | null) {
        if (
            this.disposed ||
            ['revoked', 'scope_changed', 'pending_send'].includes(
                this.state.phase,
            )
        )
            return;
        this.publish({ local: copy(draft), dirty: true });
    }
    private valid(value: unknown) {
        const result = parsePrivateDraftSnapshot(value, this.scope);
        if (!result)
            throw new ChatError(
                'The draft result could not be verified. Your work is kept in this tab.',
                0,
            );
        return result;
    }
    private options(controller: AbortController) {
        return {
            headers: { 'X-Chat-Actor': String(this.scope.actor_id) },
            signal: AbortSignal.any([
                controller.signal,
                AbortSignal.timeout(15000),
            ]),
        };
    }
    private failed(error: unknown, saving: boolean) {
        if (
            error instanceof ChatError &&
            [401, 403, 404, 419].includes(error.status)
        ) {
            this.pending = null;
            this.publish({
                phase: 'revoked',
                local: null,
                remote: null,
                dirty: false,
                error: 'Your Chat access has changed. Refresh before continuing.',
            });
            return;
        }
        if (error instanceof ChatError && error.status === 409) {
            this.pending = null;
            this.publish({
                phase: 'conflict',
                remote: null,
                error: error.message,
            });
            return;
        }
        if (error instanceof ChatError && error.status === 422)
            this.pending = null;
        this.publish({
            phase: saving && this.pending ? 'uncertain' : 'unavailable',
            error:
                error instanceof Error
                    ? error.message
                    : 'The draft could not be checked. Your work is kept in this tab.',
        });
    }
    async load() {
        if (
            this.disposed ||
            this.request ||
            this.pending ||
            this.state.phase === 'revoked'
        )
            return;
        const controller = new AbortController();
        this.request = controller;
        try {
            const remote = this.valid(
                await chatRequest(
                    `${conversationPath(this.scope.conversation_id)}/draft`,
                    this.options(controller),
                ),
            );
            if (this.disposed || controller.signal.aborted) return;
            const previous = this.state.remote;
            if (previous && remote.version < previous.version)
                throw new ChatError(
                    'An older draft result was returned. Check again before continuing.',
                    0,
                );
            if (remote.pending_send) {
                this.publish({
                    phase: 'pending_send',
                    remote,
                    local:
                        remote.state === 'scope_changed'
                            ? null
                            : this.state.dirty
                              ? this.state.local
                              : copy(remote.draft),
                    dirty: remote.state !== 'scope_changed' && this.state.dirty,
                    error: '',
                });
            } else if (remote.state === 'scope_changed') {
                this.publish({
                    phase: 'scope_changed',
                    local: null,
                    remote,
                    dirty: false,
                    error: '',
                });
            } else if (
                this.state.dirty &&
                !equal(this.state.local, remote.draft) &&
                remote.version > 0 &&
                (!previous ||
                    this.state.phase === 'pending_send' ||
                    remote.version !== previous.version ||
                    remote.boundary !== previous.boundary ||
                    !equal(remote.draft, previous.draft))
            ) {
                this.publish({ phase: 'conflict', remote, error: '' });
            } else {
                const dirty =
                    this.state.dirty && !equal(this.state.local, remote.draft);
                this.publish({
                    phase: 'ready',
                    remote,
                    local: dirty ? this.state.local : copy(remote.draft),
                    dirty,
                    error: '',
                });
            }
        } catch (error) {
            if (!this.disposed && !controller.signal.aborted)
                this.failed(error, false);
        } finally {
            this.requestFinished(controller);
        }
    }
    /** A conflict must be reviewed explicitly. This selection does not send or save anything. */
    resolve(choice: 'saved' | 'this_tab') {
        if (
            this.disposed ||
            this.request ||
            this.pending ||
            this.state.phase !== 'conflict' ||
            !this.state.remote
        )
            return;
        this.publish({
            phase: 'ready',
            local:
                choice === 'saved'
                    ? copy(this.state.remote.draft)
                    : this.state.local,
            dirty:
                choice === 'this_tab' &&
                !equal(this.state.local, this.state.remote.draft),
            error: '',
        });
    }
    async save() {
        if (
            this.disposed ||
            this.request ||
            !this.state.remote ||
            !['ready', 'uncertain', 'unavailable'].includes(this.state.phase)
        )
            return;
        if (!this.pending && !this.state.dirty) return;
        const sent = this.pending ?? {
            mutation_id: crypto.randomUUID(),
            version: this.state.remote.version,
            boundary: this.state.remote.boundary,
            draft: copy(this.state.local),
        };
        this.pending = sent;
        const controller = new AbortController();
        this.request = controller;
        this.publish({ phase: 'saving', error: '' });
        try {
            const remote = this.valid(
                await chatRequest(
                    `${conversationPath(this.scope.conversation_id)}/draft`,
                    {
                        ...this.options(controller),
                        method: 'PUT',
                        body: JSON.stringify(sent),
                    },
                ),
            );
            if (this.disposed || controller.signal.aborted) return;
            if (remote.applied_version !== sent.version + 1)
                throw new ChatError(
                    'The draft save has not been confirmed. Retry the same save.',
                    0,
                );
            this.pending = null;
            if (
                remote.state === 'scope_changed' ||
                remote.boundary !== sent.boundary
            ) {
                this.publish({
                    phase: remote.pending_send
                        ? 'pending_send'
                        : 'scope_changed',
                    remote,
                    local: null,
                    dirty: false,
                    error: '',
                });
            } else if (remote.pending_send) {
                this.publish({
                    phase: 'pending_send',
                    remote,
                    dirty: !equal(this.state.local, remote.draft),
                    error: '',
                });
            } else if (
                remote.version !== remote.applied_version ||
                !equal(remote.draft, sent.draft)
            ) {
                this.publish({ phase: 'conflict', remote, error: '' });
            } else
                this.publish({
                    phase: 'ready',
                    remote,
                    dirty: !equal(this.state.local, sent.draft),
                    error: '',
                });
        } catch (error) {
            if (!this.disposed && !controller.signal.aborted)
                this.failed(error, true);
        } finally {
            this.requestFinished(controller);
        }
    }
    /** Explicitly discard an inaccessible older audience's draft using its current server version. */
    async discardChangedScope() {
        if (
            this.disposed ||
            this.request ||
            this.pending ||
            this.state.phase !== 'scope_changed'
        )
            return;
        this.publish({ phase: 'ready', local: null, dirty: true });
        await this.save();
    }
    dispose() {
        this.disposed = true;
        this.request?.abort();
        this.request = null;
        this.idleWaiters.forEach((resolve) => resolve());
        this.idleWaiters.clear();
        this.pending = null;
        this.state = {
            phase: 'revoked',
            local: null,
            remote: null,
            dirty: false,
            error: '',
        };
        this.listeners.clear();
    }
}
