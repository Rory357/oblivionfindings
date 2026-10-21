import { ChatError, conversationPath, type ChatUploadDraft } from './api';
import { chatActorRequest } from './chat-actor-request';
import { isServerChatFile, type ChatSelectedFile } from './chat-selected-file';
import { isChatVoiceNote, voiceNoteDuration } from './chat-voice-recording';

type Outcome = {
    id: string;
    conversation_id: number;
    state:
        | 'missing'
        | 'uploaded'
        | 'scanning'
        | 'ready'
        | 'blocked'
        | 'unavailable'
        | 'changed'
        | 'discarded'
        | 'consumed';
    retry_available?: boolean;
    size_bytes?: number;
    media_kind?: 'file' | 'voice_note';
    duration_ms?: number | null;
    error_code?: string | null;
};
const busyStates = new Set(['checking', 'uploading', 'removing']);

/** One actor/conversation lifecycle; files and request identities never move between owners. */
export class ChatUploadCoordinator {
    private rows: ChatUploadDraft[];
    private listeners = new Set<() => void>();
    private requests = new Map<string, AbortController>();
    private disposed = false;

    constructor(
        private actorId: number,
        private conversationId: number,
        saved: ChatUploadDraft[],
        private persist: (rows: ChatUploadDraft[]) => void,
        private permitted: (file: ChatSelectedFile) => boolean,
        private active: () => boolean = () => true,
    ) {
        this.rows = saved.map((row) => ({
            ...row,
            state: busyStates.has(row.state) ? 'unknown' : row.state,
            progress: null,
        }));
    }

    snapshot = () => this.rows;
    activate() {
        this.disposed = false;
    }
    setPermission(permitted: (file: ChatSelectedFile) => boolean) {
        this.permitted = permitted;
    }
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };

    reconcile(files: ChatSelectedFile[]) {
        if (this.disposed || !this.active()) return;
        const next = files.map(
            (file) =>
                this.rows.find((row) => row.file === file) ?? {
                    file,
                    id: isServerChatFile(file)
                        ? file.uploadId
                        : crypto.randomUUID(),
                    state: isServerChatFile(file)
                        ? file.state
                        : ('selected' as const),
                    attempted: isServerChatFile(file),
                    error: '',
                    progress: null,
                },
        );
        for (const row of this.rows)
            if (!files.includes(row.file)) this.requests.get(row.id)?.abort();
        if (
            next.length !== this.rows.length ||
            next.some((row, index) => row !== this.rows[index])
        )
            this.publish(next);
    }

    private publish(rows: ChatUploadDraft[]) {
        if (this.disposed || !this.active()) return;
        this.rows = rows;
        this.persist(rows);
        this.listeners.forEach((listener) => listener());
    }

    private update(id: string, value: Partial<ChatUploadDraft>) {
        this.publish(
            this.rows.map((row) =>
                row.id === id ? { ...row, ...value } : row,
            ),
        );
    }

    private valid(result: Outcome, row: ChatUploadDraft) {
        if (
            !result ||
            result.id !== row.id ||
            result.conversation_id !== this.conversationId ||
            ![
                'missing',
                'uploaded',
                'scanning',
                'ready',
                'blocked',
                'unavailable',
                'changed',
                'discarded',
                'consumed',
            ].includes(result.state) ||
            (result.state === 'ready' &&
                (result.size_bytes !== row.file.size ||
                    (isChatVoiceNote(row.file) &&
                        (result.media_kind !== 'voice_note' ||
                            !Number.isFinite(result.duration_ms) ||
                            result.duration_ms! < 50 ||
                            result.duration_ms! > 120500))))
        ) {
            throw new ChatError(
                'The upload result could not be verified. Check it again before sending.',
                0,
            );
        }
        return result;
    }

    private apply(row: ChatUploadDraft, outcome: Outcome) {
        const state =
            outcome.state === 'missing' || outcome.state === 'uploaded'
                ? 'unknown'
                : outcome.state;
        let error =
            state === 'blocked'
                ? 'Blocked by the file safety check. Remove this file or choose another.'
                : state === 'changed'
                  ? 'This file changed during checking. Remove it and choose the original again.'
                  : state === 'unavailable'
                    ? 'File safety checks are unavailable. Retry this file later or remove it.'
                    : state === 'discarded'
                      ? 'This upload was removed. Remove this selection to choose it again.'
                      : state === 'consumed'
                        ? 'This upload already belongs to a sent message. Check your previous send.'
                        : '';
        if (outcome.error_code === 'voice_too_long')
            error =
                'This voice message is longer than two minutes. Remove it and record a shorter message.';
        if (outcome.error_code === 'voice_file_invalid')
            error =
                'This audio could not be verified. Remove it and record your message again.';
        if (
            ['voice_check_unavailable', 'voice_check_failed'].includes(
                outcome.error_code ?? '',
            )
        )
            error =
                'Audio checks are unavailable. Retry later or remove this recording and send a text message.';
        this.update(row.id, { state, error, progress: null });
    }

    async prepare(file: ChatSelectedFile): Promise<void> {
        const row = this.rows.find((item) => item.file === file);
        if (
            !row ||
            [
                'removing',
                'remove_failed',
                'discarded',
                'consumed',
                'blocked',
                'changed',
            ].includes(row.state) ||
            this.requests.has(row.id) ||
            this.disposed ||
            !this.active()
        )
            return;
        const controller = new AbortController();
        this.requests.set(row.id, controller);
        const current = () =>
            !this.disposed &&
            this.active() &&
            !controller.signal.aborted &&
            this.requests.get(row.id) === controller;
        const path = `${conversationPath(this.conversationId)}/uploads/${row.id}`;
        this.update(row.id, {
            state: 'checking',
            attempted: true,
            error: '',
            progress: null,
        });
        try {
            let outcome = this.valid(
                await chatActorRequest<Outcome>(this.actorId, path, {
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(15000),
                    ]),
                }),
                row,
            );
            if (!current()) return;
            if (outcome.state === 'missing') {
                if (isServerChatFile(file))
                    throw new ChatError(
                        'The original upload is no longer available. Remove it and choose the file again.',
                        409,
                    );
                if (!this.permitted(file))
                    throw new ChatError(
                        'File sharing is unavailable or this file exceeds the current limits. Your selection is kept.',
                        403,
                    );
                const body = new FormData();
                body.set('file', file);
                if (voiceNoteDuration(file) !== null)
                    body.set('media_kind', 'voice_note');
                this.update(row.id, { state: 'uploading' });
                outcome = this.valid(
                    await chatActorRequest<Outcome>(this.actorId, path, {
                        method: 'POST',
                        body,
                        signal: controller.signal,
                        onUploadProgress: (progress) => {
                            if (current())
                                this.update(row.id, {
                                    progress,
                                    state:
                                        progress.total &&
                                        progress.loaded >= progress.total
                                            ? 'scanning'
                                            : 'uploading',
                                });
                        },
                    }),
                    row,
                );
            } else if (
                (outcome.state === 'uploaded' ||
                    outcome.state === 'unavailable' ||
                    outcome.state === 'scanning') &&
                outcome.retry_available === true
            ) {
                if (!this.permitted(file))
                    throw new ChatError(
                        'File checks are unavailable in this conversation. Your selection is kept.',
                        403,
                    );
                this.update(row.id, { state: 'scanning' });
                outcome = this.valid(
                    await chatActorRequest<Outcome>(
                        this.actorId,
                        `${path}/retry`,
                        {
                            method: 'POST',
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(150000),
                            ]),
                        },
                    ),
                    row,
                );
            }
            if (current()) this.apply(row, outcome);
        } catch (error) {
            if (current())
                this.update(row.id, {
                    state: 'unknown',
                    error: (error as Error).message,
                    progress: null,
                });
        } finally {
            if (this.requests.get(row.id) === controller)
                this.requests.delete(row.id);
        }
    }

    async remove(file: ChatSelectedFile): Promise<boolean> {
        const row = this.rows.find((item) => item.file === file);
        if (!row || this.disposed || !this.active()) return false;
        if (row.state === 'removing') return false;
        this.requests.get(row.id)?.abort();
        if (
            isServerChatFile(file) &&
            file.unavailable &&
            file.state === 'consumed'
        ) {
            // Remove this stale selection only; the sent message owns the file now.
            this.update(row.id, { state: 'discarded' });
            return true;
        }
        if (!row.attempted) {
            this.update(row.id, { state: 'discarded' });
            return true;
        }
        const controller = new AbortController();
        this.requests.set(row.id, controller);
        this.update(row.id, { state: 'removing', error: '' });
        try {
            const outcome = this.valid(
                await chatActorRequest<Outcome>(
                    this.actorId,
                    `${conversationPath(this.conversationId)}/uploads/${row.id}`,
                    {
                        method: 'DELETE',
                        signal: AbortSignal.any([
                            controller.signal,
                            AbortSignal.timeout(15000),
                        ]),
                    },
                ),
                row,
            );
            if (this.disposed || !this.active() || controller.signal.aborted)
                return false;
            if (outcome.state !== 'discarded')
                throw new ChatError(
                    'Removal has not been confirmed. Retry removal before changing this file.',
                    0,
                );
            this.apply(row, outcome);
            return true;
        } catch (error) {
            if (!this.disposed && this.active() && !controller.signal.aborted)
                this.update(row.id, {
                    state: 'remove_failed',
                    error: (error as Error).message,
                });
            return false;
        } finally {
            if (this.requests.get(row.id) === controller)
                this.requests.delete(row.id);
        }
    }

    dispose() {
        // Persist the same references for explicit recovery, never automatically resend on return.
        this.publish(
            this.rows.map((row) =>
                this.requests.has(row.id)
                    ? {
                          ...row,
                          state:
                              row.state === 'removing'
                                  ? 'remove_failed'
                                  : 'unknown',
                          progress: null,
                      }
                    : row,
            ),
        );
        this.disposed = true;
        this.requests.forEach((request) => request.abort());
        this.requests.clear();
        this.listeners.clear();
    }
}
