import { ChatError, chatRequest, conversationPath } from './api';
import {
    acquireChatBrowserLease,
    type ChatBrowserLease,
} from './chat-browser-lease';
import {
    parsePrivateDraftSnapshot,
    parsePrivateSendAttempt,
    type PrivateDraftScope,
    type PrivateDraftSnapshot,
    type PrivateSendAttempt,
    type PrivateSendBody,
} from './chat-private-draft';
import {
    browserChatSendJournal,
    ChatSendJournal,
    type ChatSendReference,
} from './chat-send-journal';

type Reservation = {
    mutation_id: string;
    draft_version: number;
    draft_boundary: string;
    audience_token: string | null;
};
export type ChatSendSessionState = {
    phase:
        | 'idle'
        | 'preparing'
        | 'checking'
        | 'sending'
        | 'cancelling'
        | 'cancel_pending'
        | 'ready'
        | 'unknown'
        | 'scope_changed'
        | 'confirmed'
        | 'cancelled'
        | 'uncertain'
        | 'revoked'
        | 'other_tab'
        | 'storage_error';
    reference: ChatSendReference | null;
    attempt: PrivateSendAttempt | null;
    error: string;
};
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const same = (a: unknown, b: unknown) =>
    JSON.stringify(a) === JSON.stringify(b);

/** Explicit prepare/check/send/cancel operations. Recovery never sends automatically. */
export class ChatSendSession {
    private state: ChatSendSessionState = {
        phase: 'idle',
        reference: null,
        attempt: null,
        error: '',
    };
    private listeners = new Set<() => void>();
    private request: AbortController | null = null;
    private lifetime = new AbortController();
    private lease: ChatBrowserLease | null = null;
    private claiming: Promise<boolean> | null = null;
    private reservation: Reservation | null = null;
    private intent: PrivateSendBody | null = null;
    private disposed = false;

    constructor(
        private readonly scope: PrivateDraftScope,
        private readonly journal: ChatSendJournal | null = browserChatSendJournal(
            scope.actor_id,
            scope.conversation_id,
        ),
        private readonly locks: LockManager | undefined = navigator.locks,
    ) {
        this.scope = { ...scope };
    }
    snapshot = () => this.state;
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    private publish(patch: Partial<ChatSendSessionState>) {
        if (this.disposed) return;
        this.state = { ...this.state, ...patch };
        this.listeners.forEach((listener) => listener());
    }
    private storageError() {
        this.publish({
            phase: 'storage_error',
            error: 'Message recovery could not be saved on this browser. Check the current attempt before continuing.',
        });
    }
    private async claim(): Promise<boolean> {
        if (this.disposed || this.state.phase === 'revoked') return false;
        if (this.lease) return true;
        if (this.claiming) return this.claiming;
        this.claiming = (async () => {
            try {
                if (
                    !this.journal ||
                    this.journal.actor !== this.scope.actor_id ||
                    this.journal.conversation !== this.scope.conversation_id ||
                    this.journal.read().status === 'unavailable'
                ) {
                    this.storageError();
                    return false;
                }
                const lease = await acquireChatBrowserLease(
                    `oblivion-chat-send-${this.scope.actor_id}-${this.scope.conversation_id}`,
                    this.lifetime.signal,
                    this.locks,
                    'This browser cannot coordinate message recovery across tabs. Use a browser with Web Locks support.',
                );
                if (this.disposed) {
                    lease?.release();
                    return false;
                }
                if (!lease) {
                    this.publish({
                        phase: 'other_tab',
                        error: 'This message is being handled in another tab. Return there or close that tab, then check again.',
                    });
                    return false;
                }
                this.lease = lease;
                return true;
            } catch (error) {
                this.publish({
                    phase: 'storage_error',
                    error: (error as Error).message,
                });
                return false;
            }
        })();
        try {
            return await this.claiming;
        } finally {
            this.claiming = null;
        }
    }
    private release() {
        this.lease?.release();
        this.lease = null;
    }
    private current(controller: AbortController) {
        return (
            !this.disposed &&
            this.request === controller &&
            !controller.signal.aborted
        );
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
    private path(mutation: string) {
        return `${conversationPath(this.scope.conversation_id)}/draft/send-attempts/${encodeURIComponent(mutation)}`;
    }
    private valid(value: unknown, mutation: string): PrivateSendAttempt {
        const attempt = parsePrivateSendAttempt(value, this.scope, mutation);
        if (
            !attempt ||
            (attempt.body && this.intent && !same(attempt.body, this.intent))
        )
            throw new ChatError(
                'The message outcome could not be verified. Keep this attempt and check again.',
                0,
            );
        if (attempt.body && !this.intent) this.intent = copy(attempt.body);
        return attempt;
    }
    private apply(attempt: PrivateSendAttempt) {
        const reference = this.state.reference;
        if (!reference || attempt.mutation_id !== reference.mutation) return;
        if (['confirmed', 'cancelled'].includes(attempt.state)) {
            const stored = this.journal?.read();
            // A confirmed terminal receipt may clear either cancel-intent value for this exact identity.
            if (
                stored?.status !== 'saved' ||
                stored.record.mutation !== reference.mutation ||
                !this.journal?.clear(stored.record)
            ) {
                this.publish({ attempt });
                this.storageError();
                return;
            }
            this.publish({
                phase: attempt.state as 'confirmed' | 'cancelled',
                attempt,
                reference: null,
                error: '',
            });
            this.reservation = null;
            this.intent = null;
            this.release();
            return;
        }
        const phase = reference.cancelling
            ? 'cancel_pending'
            : attempt.state === 'missing'
              ? 'unknown'
              : attempt.state === 'scope_changed'
                ? 'scope_changed'
                : 'ready';
        this.publish({ phase, attempt, error: '' });
    }
    private failed(error: unknown) {
        if (
            error instanceof ChatError &&
            [401, 403, 404, 419].includes(error.status)
        ) {
            this.intent = null;
            this.reservation = null;
            this.publish({
                phase: 'revoked',
                attempt: null,
                error: 'Your Chat access has changed. Refresh before continuing.',
            });
            this.release();
        } else
            this.publish({
                phase: this.state.reference?.cancelling
                    ? 'cancel_pending'
                    : 'uncertain',
                error:
                    error instanceof Error
                        ? error.message
                        : 'The message outcome is not confirmed. Check again.',
            });
    }
    /** Adopt an ID-only local reference or a pending server snapshot, then perform a fresh read only. */
    async recover(serverAttempt: PrivateSendAttempt | null = null) {
        if (!(await this.claim()) || this.request) return;
        const stored = this.journal!.read();
        if (stored.status === 'unavailable') {
            this.storageError();
            return;
        }
        let reference = stored.record;
        if (!reference && serverAttempt) {
            const safe = parsePrivateSendAttempt(serverAttempt, this.scope);
            if (!safe || !['pending', 'scope_changed'].includes(safe.state)) {
                this.storageError();
                return;
            }
            reference = {
                version: 1,
                actor: this.scope.actor_id,
                conversation: this.scope.conversation_id,
                mutation: safe.mutation_id,
                cancelling: false,
            };
            if (!this.journal!.write(reference, null)) {
                this.storageError();
                return;
            }
        }
        if (!reference) {
            this.publish({
                phase: 'idle',
                reference: null,
                attempt: null,
                error: '',
            });
            this.release();
            return;
        }
        const wasStopping =
            this.state.reference?.mutation === reference.mutation &&
            this.state.reference.cancelling;
        this.publish({
            reference: {
                ...reference,
                cancelling: reference.cancelling || !!wasStopping,
            },
        });
        await this.check();
    }
    async prepare(draft: PrivateDraftSnapshot, audienceToken: string | null) {
        if (!(await this.claim()) || this.request || this.state.reference)
            return;
        const stored = this.journal!.read();
        if (stored.status !== 'empty') {
            await this.recover();
            return;
        }
        const safe = parsePrivateDraftSnapshot(draft, this.scope);
        if (
            !safe ||
            safe.state !== 'saved' ||
            !safe.draft ||
            safe.pending_send
        ) {
            this.publish({
                phase: 'uncertain',
                error: 'Review your saved draft before sending.',
            });
            this.release();
            return;
        }
        const mutation = crypto.randomUUID();
        const reference: ChatSendReference = {
            version: 1,
            actor: this.scope.actor_id,
            conversation: this.scope.conversation_id,
            mutation,
            cancelling: false,
        };
        if (!this.journal!.write(reference, null)) {
            this.storageError();
            return;
        }
        this.reservation = {
            mutation_id: mutation,
            draft_version: safe.version,
            draft_boundary: safe.boundary,
            audience_token: audienceToken,
        };
        this.intent = {
            recoverable_send: true,
            ...this.reservation,
            content: safe.draft.content,
            reply_to_id: safe.draft.reply_to_id,
            mentions: copy(safe.draft.mentions),
            upload_ids: safe.draft.uploads.map((upload) => upload.id),
            captions: safe.draft.uploads.map((upload) => upload.caption),
        };
        this.publish({ reference, attempt: null });
        await this.retryPreparation();
    }
    /** Available only while this page retains the original reservation. Reload recovery cannot guess it. */
    async retryPreparation() {
        if (
            this.request ||
            !this.reservation ||
            !this.state.reference ||
            this.state.reference.cancelling
        )
            return;
        if (
            !(await this.claim()) ||
            this.request ||
            !this.reservation ||
            !this.state.reference ||
            this.state.reference.cancelling
        )
            return;
        const controller = new AbortController();
        this.request = controller;
        this.publish({ phase: 'preparing', error: '' });
        try {
            const result = await chatRequest(
                `${conversationPath(this.scope.conversation_id)}/draft/send-attempts`,
                {
                    ...this.options(controller),
                    method: 'POST',
                    body: JSON.stringify(this.reservation),
                },
            );
            if (this.current(controller))
                this.apply(this.valid(result, this.reservation.mutation_id));
        } catch (error) {
            if (this.current(controller)) this.failed(error);
        } finally {
            if (this.request === controller) this.request = null;
        }
    }
    async check() {
        if (this.request || !this.state.reference) return;
        if (!(await this.claim()) || this.request || !this.state.reference)
            return;
        const mutation = this.state.reference.mutation;
        const controller = new AbortController();
        this.request = controller;
        this.publish({ phase: 'checking', error: '' });
        try {
            const result = await chatRequest(
                this.path(mutation),
                this.options(controller),
            );
            if (this.current(controller))
                this.apply(this.valid(result, mutation));
        } catch (error) {
            if (this.current(controller)) this.failed(error);
        } finally {
            if (this.request === controller) this.request = null;
        }
    }
    async send() {
        if (
            this.request ||
            !this.state.reference ||
            this.state.reference.cancelling
        )
            return;
        if (
            !(await this.claim()) ||
            this.request ||
            !this.state.reference ||
            this.state.reference.cancelling
        )
            return;
        const mutation = this.state.reference.mutation;
        const controller = new AbortController();
        this.request = controller;
        this.publish({ phase: 'checking', error: '' });
        try {
            const read = await chatRequest(
                this.path(mutation),
                this.options(controller),
            );
            if (!this.current(controller)) return;
            const fresh = this.valid(read, mutation);
            this.apply(fresh);
            if (
                fresh.state !== 'pending' ||
                !fresh.can_retry ||
                !fresh.body ||
                this.state.reference?.cancelling
            )
                return;
            this.publish({ phase: 'sending' });
            await chatRequest(
                `${conversationPath(this.scope.conversation_id)}/messages`,
                {
                    ...this.options(controller),
                    method: 'POST',
                    body: JSON.stringify(fresh.body),
                },
            );
            if (!this.current(controller)) return;
            const outcome = await chatRequest(
                this.path(mutation),
                this.options(controller),
            );
            if (this.current(controller))
                this.apply(this.valid(outcome, mutation));
        } catch (error) {
            if (this.current(controller)) this.failed(error);
        } finally {
            if (this.request === controller) this.request = null;
        }
    }
    async cancel() {
        if (!this.state.reference) return;
        if (!(await this.claim()) || !this.state.reference) return;
        this.request?.abort();
        const stopping = { ...this.state.reference, cancelling: true };
        const stored = this.journal!.read();
        // Try to persist stop intent first. Even if storage fails, the safe server cancellation is still attempted.
        if (
            stored.status === 'saved' &&
            stored.record.mutation === stopping.mutation
        )
            this.journal!.write(stopping, stored.record);
        this.publish({ reference: stopping, phase: 'cancelling', error: '' });
        const controller = new AbortController();
        this.request = controller;
        try {
            const result = await chatRequest(
                `${this.path(stopping.mutation)}/cancel`,
                { ...this.options(controller), method: 'POST' },
            );
            if (this.current(controller))
                this.apply(this.valid(result, stopping.mutation));
        } catch (error) {
            if (this.current(controller)) this.failed(error);
        } finally {
            if (this.request === controller) this.request = null;
        }
    }
    dispose() {
        this.disposed = true;
        this.request?.abort();
        this.lifetime.abort();
        this.release();
        this.request = null;
        this.intent = null;
        this.reservation = null;
        this.state = {
            phase: 'revoked',
            reference: null,
            attempt: null,
            error: '',
        };
        this.listeners.clear();
    }
}
