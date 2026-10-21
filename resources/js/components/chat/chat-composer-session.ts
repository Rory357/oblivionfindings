import { onChatActorReset } from './api';
import {
    PrivateChatDraftSession,
    type PrivateChatDraft,
    type PrivateDraftScope,
    type PrivateDraftState,
} from './chat-private-draft';
import {
    ChatSendSession,
    type ChatSendSessionState,
} from './chat-send-session';

const same = (a: unknown, b: unknown) =>
    JSON.stringify(a) === JSON.stringify(b);
export type ComposerRecoveryState = {
    draft: PrivateDraftState;
    send: ChatSendSessionState;
    choice: 'loading' | 'resume' | 'ready';
    busy: boolean;
    replacement: { revision: number; value: PrivateChatDraft | null } | null;
};

/** Survives drawer/full-page/call handoff. Account and canonical client changes dispose it. */
export class ChatComposerSession {
    readonly draft: PrivateChatDraftSession;
    readonly send: ChatSendSession;
    private state: ComposerRecoveryState;
    private listeners = new Set<() => void>();
    private started = false;
    private disposed = false;
    private submitted: PrivateChatDraft | null = null;
    private terminal: string | null = null;
    private revision = 0;
    private operations = 0;

    constructor(
        readonly scope: PrivateDraftScope,
        initial?: PrivateChatDraft | null,
    ) {
        this.draft = new PrivateChatDraftSession(scope, initial);
        this.send = new ChatSendSession(scope);
        this.state = {
            draft: this.draft.snapshot(),
            send: this.send.snapshot(),
            choice: 'loading',
            busy: false,
            replacement: null,
        };
        this.draft.subscribe(() =>
            this.publish({ draft: this.draft.snapshot() }),
        );
        this.send.subscribe(() => this.publish({ send: this.send.snapshot() }));
    }
    snapshot = () => this.state;
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    private publish(patch: Partial<ComposerRecoveryState>) {
        if (this.disposed) return;
        this.state = { ...this.state, ...patch };
        this.listeners.forEach((listener) => listener());
    }
    private begin() {
        this.operations++;
        this.publish({ busy: true });
    }
    private end() {
        this.operations = Math.max(0, this.operations - 1);
        this.publish({ busy: this.operations > 0 });
    }
    private replace(value: PrivateChatDraft | null) {
        this.publish({
            replacement: { revision: ++this.revision, value },
            choice: 'ready',
        });
    }
    acknowledgeReplacement(revision: number) {
        if (this.state.replacement?.revision === revision)
            this.publish({ replacement: null });
    }
    async start() {
        if (this.started || this.disposed) return;
        this.started = true;
        await this.check();
    }
    async check() {
        if (this.state.busy || this.disposed) return;
        this.begin();
        try {
            await this.draft.load();
            const current = this.draft.snapshot();
            if (current.phase === 'revoked') return;
            await this.send.recover(current.remote?.pending_send ?? null);
            await this.settle();
            if (this.state.choice === 'loading') {
                const latest = this.draft.snapshot();
                this.publish({
                    choice: !latest.dirty && latest.local ? 'resume' : 'ready',
                });
            }
        } finally {
            this.end();
        }
    }
    edit(value: PrivateChatDraft | null) {
        if (
            this.state.choice !== 'ready' ||
            this.state.busy ||
            this.state.send.reference ||
            same(this.state.draft.local, value)
        )
            return;
        this.draft.edit(value);
    }
    async save() {
        if (
            this.state.choice !== 'ready' ||
            this.state.busy ||
            this.state.send.reference
        )
            return;
        await this.draft.save();
    }
    resume(choice: 'saved' | 'this_tab' = 'saved') {
        if (this.state.busy || this.state.send.reference) return;
        if (this.state.draft.phase === 'conflict') this.draft.resolve(choice);
        if (this.draft.snapshot().phase !== 'ready') return;
        if (choice === 'this_tab') {
            // The visible tab can contain local files that have not been uploaded yet.
            // Keep those exact selections; the protected payload cannot recreate their bytes.
            this.publish({ choice: 'ready' });
            return;
        }
        this.replace(this.draft.snapshot().local);
    }
    async discard() {
        if (this.state.busy || this.state.send.reference) return;
        this.begin();
        try {
            if (this.state.draft.phase === 'scope_changed')
                await this.draft.discardChangedScope();
            else {
                if (this.state.draft.phase === 'conflict')
                    this.draft.resolve('saved');
                if (this.draft.snapshot().phase !== 'ready') return;
                this.draft.edit(null);
                await this.draft.save();
            }
            if (
                this.draft.snapshot().phase === 'ready' &&
                !this.draft.snapshot().dirty
            )
                this.replace(null);
        } finally {
            this.end();
        }
    }
    async submit(value: PrivateChatDraft, audienceToken: string | null) {
        if (
            this.state.busy ||
            this.state.choice !== 'ready' ||
            this.state.send.reference ||
            this.disposed
        )
            return;
        this.edit(value);
        this.begin();
        try {
            const saved = await this.draft.flush();
            if (!saved || !same(saved.draft, value) || this.disposed) return;
            this.submitted = value;
            await this.send.prepare(saved, audienceToken);
            if (this.send.snapshot().phase === 'ready') await this.send.send();
            await this.settle();
        } finally {
            this.end();
        }
    }
    async attempt(action: 'check' | 'send' | 'cancel') {
        if (
            this.disposed ||
            (this.state.busy && action !== 'cancel') ||
            (action === 'cancel' &&
                (!this.state.send.reference ||
                    this.state.send.phase === 'cancelling'))
        )
            return;
        this.begin();
        try {
            if (action === 'send') await this.send.send();
            else if (action === 'cancel') await this.send.cancel();
            else if (this.send.snapshot().reference) await this.send.check();
            else
                await this.send.recover(
                    this.draft.snapshot().remote?.pending_send ?? null,
                );
            await this.settle();
        } finally {
            this.end();
        }
    }
    private async settle() {
        const send = this.send.snapshot();
        if (
            !['confirmed', 'cancelled'].includes(send.phase) ||
            !send.attempt ||
            this.terminal === send.attempt.mutation_id
        )
            return;
        this.terminal = send.attempt.mutation_id;
        // Only this page's exact submitted draft can be cleared automatically.
        // After reload, retain local work and review the authoritative server draft.
        if (
            send.phase === 'confirmed' &&
            this.submitted &&
            same(this.draft.snapshot().local, this.submitted)
        ) {
            this.draft.edit(null);
            this.replace(null);
        }
        this.submitted = null;
        await this.draft.load();
    }
    dispose() {
        this.disposed = true;
        this.draft.dispose();
        this.send.dispose();
        this.submitted = null;
        this.state = {
            draft: this.draft.snapshot(),
            send: this.send.snapshot(),
            busy: false,
            choice: 'loading',
            replacement: null,
        };
        this.listeners.clear();
    }
}

const sessions = new Map<string, ChatComposerSession>();
export function forgetChatComposerSession(actor: number, conversation: number) {
    const key = `${actor}:${conversation}`;
    sessions.get(key)?.dispose();
    sessions.delete(key);
}
onChatActorReset(() => {
    sessions.forEach((session) => session.dispose());
    sessions.clear();
});
export function chatComposerSession(
    scope: PrivateDraftScope,
    initial?: PrivateChatDraft | null,
) {
    const key = `${scope.actor_id}:${scope.conversation_id}`;
    let session = sessions.get(key);
    if (session && session.scope.client_id !== scope.client_id) {
        session.dispose();
        sessions.delete(key);
        session = undefined;
    }
    if (!session) {
        session = new ChatComposerSession(scope, initial);
        sessions.set(key, session);
    }
    return session;
}
