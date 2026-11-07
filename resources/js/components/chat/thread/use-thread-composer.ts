import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    ChatError,
    conversationPath,
    readChatDraft,
    readPendingChatSend,
    saveChatDraft,
    saveChatThreadMemory,
    savePendingChatSend,
    type ChatEditDraft,
    type ChatThreadMemory,
    type ChatUploadProgress,
} from '../api';
import { chatActorRequest } from '../chat-actor-request';
import { chatFileIssue } from '../chat-attachment-queue';
import { type ChatSelectedFile } from '../chat-selected-file';
import { isChatVoiceNote } from '../chat-voice-recording';
import { createTypingSignal } from '../live/typing';
import type {
    ChatConversation,
    ChatHistory,
    ChatMention,
    ChatMessage,
    ChatPolicy,
} from '../types';
import { useChatUploads } from '../use-chat-uploads';
import {
    addToChatOutbox,
    CHAT_OUTBOX_LIMIT,
    readChatOutbox,
} from './chat-outbox';
import { useComposerRecovery } from './use-composer-recovery';
import type { ThreadHistory } from './use-thread-history';

/**
 * Everything the person is writing: text, mentions, reply, files and voice notes; the protected
 * server draft (when draft recovery is on); and sending, including the duplicate-safe check of a
 * send whose result is unknown. While the browser is offline, Send puts a text message in the
 * outbox instead (see chat-outbox.ts).
 */
export function useThreadComposer({
    actorId,
    conversationId,
    draftKey: key,
    keyRef,
    conversation,
    policy,
    history,
    online,
    onChange,
}: {
    actorId: number;
    conversationId: number;
    draftKey: string;
    keyRef: { current: string };
    conversation: ChatConversation | undefined;
    policy: ChatPolicy;
    history: ThreadHistory;
    online: boolean;
    onChange?: () => void;
}) {
    const { historyRef, load, active } = history;
    const hasConversation = Boolean(conversation);
    const [draft, setDraft] = useState('');
    const [editing, setEditing] = useState<ChatEditDraft | null>(null);
    const [mentions, setMentions] = useState<ChatMention[]>([]);
    // Typing: sent at most every 3 s while live; others see a line for 6 s (spec section 6).
    const typing = useMemo(
        () => createTypingSignal({ actorId, conversationId }),
        [actorId, conversationId],
    );
    useEffect(() => () => typing.stop(), [typing]);
    const [files, setFilesState] = useState<ChatSelectedFile[]>([]);
    const [fileCaptions, setFileCaptions] = useState<string[]>([]);
    const [fileMemoryKey, setFileMemoryKey] = useState<string | null>(null);
    const selection = useRef({ files, captions: fileCaptions });
    selection.current = { files, captions: fileCaptions };
    const [attachmentsOpen, setAttachmentsOpen] = useState(false);
    const [recordingVoice, setRecordingVoice] = useState(false);
    const voiceTrigger = useRef<HTMLButtonElement>(null);
    const [uploadProgress, setUploadProgress] =
        useState<ChatUploadProgress | null>(null);
    const attachmentTrigger = useRef<HTMLButtonElement>(null);
    const sendRequest = useRef<AbortController | null>(null);
    const [reply, setReplyState] = useState<ChatMessage | null>(null);
    const [unresolvedReply, setUnresolvedReply] = useState<number | null>(null);
    const [replyError, setReplyError] = useState('');
    const [replyRetry, setReplyRetry] = useState(0);
    const replyRecovery = useRef<AbortController | null>(null);
    const [sending, setSending] = useState(false);
    const [checkingSend, setCheckingSend] = useState(false);
    const [sendError, setSendError] = useState('');
    const [audienceReview, setAudienceReview] =
        useState<ChatConversation | null>(null);
    const composerForm = useRef<HTMLFormElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const mutation = useRef<string | null>(null);
    const pendingBody = useRef<FormData | null>(null);
    // The unconfirmed send: the ref is read by handlers, the state by render.
    const [pending, setPendingState] = useState<FormData | null>(null);
    const setPending = useCallback((body: FormData | null) => {
        pendingBody.current = body;
        setPendingState(body);
    }, []);

    const focus = useCallback(() => {
        composerForm.current?.querySelector('textarea')?.focus();
    }, []);
    const focusSoon = useCallback(() => {
        requestAnimationFrame(focus);
    }, [focus]);

    const uploads = useChatUploads(
        actorId,
        key,
        conversationId,
        files,
        !!conversation && fileMemoryKey === key,
        (file) =>
            !!conversation?.can_send &&
            policy.enabled &&
            policy.files_enabled &&
            files.length <= policy.max_files &&
            !chatFileIssue(file, policy.max_file_mb) &&
            (!isChatVoiceNote(file) || policy.voice_notes_enabled === true),
    );
    const filesReady = files.every(
        (file) =>
            (!isChatVoiceNote(file) || policy.voice_notes_enabled === true) &&
            uploads.rows.some(
                (row) => row.file === file && row.state === 'ready',
            ),
    );
    const setFiles = (
        value: ChatSelectedFile[],
        captions = value.map((file, index) =>
            file === files[index] ? (fileCaptions[index] ?? '') : '',
        ),
    ) => {
        selection.current = { files: value, captions };
        setFilesState(value);
        setFileCaptions(captions);
        saveChatThreadMemory(keyRef.current, {
            files: value,
            fileCaptions: captions,
        });
    };
    const removeFile = async (file: ChatSelectedFile) => {
        const removalKey = key;
        if (!(await uploads.remove(file)) || keyRef.current !== removalKey)
            return false;
        const current = selection.current;
        setFiles(
            current.files.filter((item) => item !== file),
            current.captions.filter(
                (_, index) => current.files[index] !== file,
            ),
        );
        return true;
    };
    const setCaption = (index: number, caption: string) => {
        const next = files.map((_, i) =>
            i === index ? caption : (fileCaptions[i] ?? ''),
        );
        setFileCaptions(next);
        selection.current = { files, captions: next };
        saveChatThreadMemory(key, { fileCaptions: next });
    };
    useEffect(() => {
        setSending(false);
        setCheckingSend(false);
        setUploadProgress(null);
        setAttachmentsOpen(false);
        setRecordingVoice(false);
        return () => {
            sendRequest.current?.abort();
            sendRequest.current = null;
        };
    }, [actorId, conversationId]);
    const setReply = (value: ChatMessage | null) => {
        replyRecovery.current?.abort();
        setUnresolvedReply(null);
        setReplyError('');
        setReplyState(value);
        saveChatThreadMemory(keyRef.current, { replyId: value?.id ?? null });
    };
    const retryReply = () => setReplyRetry((value) => value + 1);

    /** Live changes replaced these messages: follow them in the reply being written. */
    const messagesChanged = useCallback(
        (updated: Map<number, ChatMessage>) => {
            setReplyState((current) => {
                if (!current) return current;
                const replacement = updated.get(current.id);
                if (replacement?.deleted) {
                    saveChatThreadMemory(keyRef.current, { replyId: null });
                    return null;
                }
                return replacement ?? current;
            });
        },
        [keyRef],
    );

    /** Clears the composer of the previous conversation. */
    const reset = useCallback(() => {
        setReplyState(null);
        setUnresolvedReply(null);
        setReplyError('');
        setFilesState([]);
        setFileMemoryKey(null);
        setSendError('');
        setAudienceReview(null);
        mutation.current = null;
        setPending(null);
        // The canonical client key is restored once the authorised projection arrives.
        setDraft('');
        setEditing(null);
        setMentions([]);
    }, [setPending]);

    /** Restores what the person was writing in this conversation (memory only). */
    const restore = useCallback(
        (
            memoryKey: string,
            remembered: ChatThreadMemory,
            signal: AbortController,
        ) => {
            setDraft(readChatDraft(memoryKey));
            setEditing(remembered.editing ?? null);
            setMentions(remembered.mentions);
            setFilesState(remembered.files);
            setFileCaptions(
                remembered.fileCaptions ?? remembered.files.map(() => ''),
            );
            return () => {
                if (remembered.replyId) {
                    replyRecovery.current = signal;
                    setUnresolvedReply(remembered.replyId);
                    setReplyError('');
                    void chatActorRequest<ChatHistory>(
                        actorId,
                        `${conversationPath(conversationId)}/messages?around=${remembered.replyId}`,
                        { signal: signal.signal },
                    )
                        .then((result) => {
                            if (
                                signal.signal.aborted ||
                                keyRef.current !== memoryKey
                            )
                                return;
                            const original = result.messages.find(
                                (message) =>
                                    message.id === remembered.replyId &&
                                    !message.deleted,
                            );
                            setReplyState(original ?? null);
                            setUnresolvedReply(null);
                            if (!original) {
                                saveChatThreadMemory(memoryKey, {
                                    replyId: null,
                                });
                                toast.error(
                                    'The original reply is no longer available. Your draft and files have been kept.',
                                );
                            }
                        })
                        .catch(() => {
                            if (
                                !signal.signal.aborted &&
                                keyRef.current === memoryKey
                            )
                                setReplyError(
                                    'Your previous reply could not be checked. Retry or remove the reply before sending.',
                                );
                        });
                }
                setPending(readPendingChatSend(memoryKey));
                if (pendingBody.current) {
                    setFileCaptions(
                        pendingBody.current.getAll('captions[]').map(String),
                    );
                    if (pendingBody.current.has('files[]'))
                        setFilesState(
                            pendingBody.current
                                .getAll('files[]')
                                .filter(
                                    (file): file is File =>
                                        file instanceof File,
                                ),
                        );
                    setSendError(
                        'A previous send has not been confirmed. Retry to check it safely.',
                    );
                }
                setFileMemoryKey(memoryKey);
            };
        },
        [actorId, conversationId, keyRef, setPending],
    );

    const {
        composerSession,
        recovery,
        recoveryEnabled,
        recoveryLocked,
        recoveryValue,
        restoreError,
        retryRestore,
    } = useComposerRecovery({
        actorId,
        conversationId,
        draftKey: key,
        keyRef,
        hasConversation,
        historyRef,
        policy,
        pending,
        fileMemoryKey,
        value: { draft, mentions, files, fileCaptions, reply, unresolvedReply },
        uploadRows: uploads.rows,
        set: {
            setDraft,
            setMentions,
            setFiles: setFilesState,
            setFileCaptions,
            setReply: setReplyState,
            setUnresolvedReply,
            setReplyRetry,
        },
        replyRecovery,
        composerForm,
    });

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (
                recovery?.draft.dirty ||
                recovery?.draft.phase === 'saving' ||
                readChatOutbox(key).length > 0 ||
                files.some(
                    (file) =>
                        !uploads.rows.some(
                            (row) => row.file === file && row.state === 'ready',
                        ),
                )
            ) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [recovery, files, uploads.rows, key]);
    const terminalSend = recovery?.send.attempt;
    const terminalNotified = useRef('');
    useEffect(() => {
        if (
            !terminalSend ||
            !['confirmed', 'cancelled'].includes(terminalSend.state)
        )
            return;
        const identity = `${actorId}:${conversationId}:${terminalSend.mutation_id}:${terminalSend.state}`;
        if (terminalNotified.current === identity) return;
        terminalNotified.current = identity;
        void load(undefined, true);
        onChange?.();
    }, [terminalSend, load, onChange, actorId, conversationId]);

    /** Offline: the text waits in the outbox until the person sends it after reconnecting. */
    function queueOffline() {
        if (files.length) {
            setSendError(
                "You're offline. Files need a connection: send them when you're back online, or remove them to keep writing.",
            );
            return;
        }
        const entry = addToChatOutbox(key, {
            content: draft,
            mentions,
            reply,
        });
        if (!entry) {
            toast.error(
                `Up to ${CHAT_OUTBOX_LIMIT} messages can wait while you're offline. Send or remove some first.`,
            );
            return;
        }
        typing.stop();
        setSendError('');
        saveChatDraft(key, '');
        saveChatThreadMemory(key, { mentions: [] });
        setDraft('');
        setMentions([]);
        setReply(null);
        if (history.contextRef.current === null) history.setAtEnd(true);
    }

    async function send(checkOnly = false) {
        if (unresolvedReply && !pendingBody.current) return;
        if (
            sendRequest.current ||
            sending ||
            (!draft.trim() && !files.length && !pendingBody.current)
        )
            return;
        if (!online && !pendingBody.current) {
            if (!recoveryLocked) queueOffline();
            return;
        }
        if (
            !pendingBody.current &&
            (files.length > policy.max_files ||
                !filesReady ||
                files.some((file) => chatFileIssue(file, policy.max_file_mb)))
        ) {
            setAttachmentsOpen(true);
            return;
        }
        if (!pendingBody.current && conversation?.audience_review_required) {
            setAudienceReview(conversation);
            return;
        }
        if (recoveryEnabled && !pendingBody.current) {
            if (recoveryLocked || !composerSession || !recoveryValue) return;
            setSendError('');
            await composerSession.submit(
                recoveryValue,
                conversation?.audience_review_token ?? null,
            );
            return;
        }
        const id = conversationId;
        const submittedKey = key;
        const retrying = !!pendingBody.current;
        if (!pendingBody.current) {
            const body = new FormData();
            mutation.current = crypto.randomUUID();
            body.set('mutation_id', mutation.current);
            body.set('content', draft);
            if (conversation?.audience_review_token)
                body.set('audience_token', conversation.audience_review_token);
            mentions.forEach((mention, index) => {
                body.set(
                    `mentions[${index}][user_id]`,
                    String(mention.user_id),
                );
                body.set(`mentions[${index}][start]`, String(mention.start));
                body.set(`mentions[${index}][length]`, String(mention.length));
            });
            if (reply) body.set('reply_to_id', String(reply.id));
            files.forEach((file, index) => {
                body.append(
                    'upload_ids[]',
                    uploads.rows.find(
                        (row) => row.file === file && row.state === 'ready',
                    )!.id,
                );
                body.append('captions[]', fileCaptions[index] ?? '');
            });
            setPending(body);
            savePendingChatSend(submittedKey, body);
        }
        const outgoing = pendingBody.current;
        if (!outgoing) return;
        setSending(true);
        setCheckingSend(retrying);
        setUploadProgress(null);
        setSendError('');
        const controller = new AbortController();
        sendRequest.current = controller;
        try {
            let confirmed = false;
            if (retrying) {
                const reference = String(outgoing.get('mutation_id'));
                const outcome = await chatActorRequest<{
                    state: 'confirmed' | 'unconfirmed';
                    conversation_id: number;
                    mutation_id: string;
                    message_id: number | null;
                    can_retry: boolean;
                }>(
                    actorId,
                    `${conversationPath(id)}/send-outcomes/${encodeURIComponent(reference)}`,
                    {
                        signal: AbortSignal.any([
                            controller.signal,
                            AbortSignal.timeout(15000),
                        ]),
                    },
                );
                if (
                    controller.signal.aborted ||
                    keyRef.current !== submittedKey
                )
                    return;
                if (
                    !outcome ||
                    outcome.conversation_id !== id ||
                    outcome.mutation_id !== reference ||
                    !['confirmed', 'unconfirmed'].includes(outcome.state) ||
                    typeof outcome.can_retry !== 'boolean' ||
                    (outcome.state === 'unconfirmed' &&
                        outcome.message_id !== null) ||
                    (outcome.state === 'confirmed' &&
                        !(
                            typeof outcome.message_id === 'number' &&
                            Number.isSafeInteger(outcome.message_id) &&
                            outcome.message_id > 0
                        ))
                ) {
                    throw new ChatError(
                        'The send outcome could not be verified. Your pending message is kept.',
                        0,
                    );
                }
                confirmed = outcome.state === 'confirmed';
                if (!confirmed && (checkOnly || !outcome.can_retry))
                    throw new ChatError(
                        'No confirmed result yet. Your pending message is kept; check again when the conversation is available.',
                        0,
                    );
                setCheckingSend(false);
            }
            if (!confirmed)
                await chatActorRequest(
                    actorId,
                    `${conversationPath(id)}/messages`,
                    {
                        method: 'POST',
                        body: outgoing,
                        signal: controller.signal,
                        onUploadProgress: outgoing.has('files[]')
                            ? (progress) => {
                                  if (
                                      keyRef.current === submittedKey &&
                                      sendRequest.current === controller &&
                                      !controller.signal.aborted
                                  )
                                      setUploadProgress(progress);
                              }
                            : undefined,
                    },
                );
            if (controller.signal.aborted || keyRef.current !== submittedKey)
                return;
            saveChatDraft(submittedKey, '');
            savePendingChatSend(submittedKey, null);
            saveChatThreadMemory(submittedKey, {
                mentions: [],
                files: [],
                fileCaptions: [],
                replyId: null,
                readAnchor: null,
                readOffset: 0,
            });
            if (active.current !== id) return;
            typing.stop();
            setDraft('');
            setMentions([]);
            setFiles([]);
            setReply(null);
            setPending(null);
            mutation.current = null;
            history.followLatest();
            await load(undefined, true);
            onChange?.();
        } catch (error) {
            if (keyRef.current === submittedKey && !controller.signal.aborted) {
                if (
                    error instanceof ChatError &&
                    error.code === 'chat_audience_review_required'
                ) {
                    // The server checked the send reference and confirms this write did not happen.
                    setPending(null);
                    mutation.current = null;
                    savePendingChatSend(submittedKey, null);
                    setSendError('');
                    const current = await load(undefined, true);
                    if (current && active.current === id)
                        setAudienceReview(current);
                    return;
                }
                setSendError((error as Error).message);
                if (
                    !retrying &&
                    error instanceof ChatError &&
                    (error.status === 422 ||
                        error.code === 'chat_file_unavailable')
                ) {
                    setPending(null);
                    mutation.current = null;
                    savePendingChatSend(submittedKey, null);
                }
            }
        } finally {
            if (sendRequest.current === controller) {
                sendRequest.current = null;
                if (keyRef.current === submittedKey) {
                    setSending(false);
                    setCheckingSend(false);
                    setUploadProgress(null);
                }
            }
        }
    }

    /** Text typed into the composer. */
    function write(value: string, selected: ChatMention[]) {
        setDraft(value);
        typing.input(value);
        setMentions(selected);
        saveChatDraft(key, value);
        saveChatThreadMemory(key, { mentions: selected });
    }

    /** Puts a message back into the composer (an outbox message the person wants to change). */
    function loadIntoComposer(message: {
        content: string;
        mentions: ChatMention[];
        reply: ChatMessage | null;
    }) {
        write(message.content, message.mentions);
        setReply(message.reply);
        focusSoon();
    }

    const locked = sending || recoveryLocked || !!pending;
    const empty =
        !draft && !files.length && !reply && !unresolvedReply && !locked;

    return {
        key,
        keyRef,
        draft,
        setDraft,
        write,
        mentions,
        editing,
        setEditing,
        files,
        setFiles,
        fileCaptions,
        setCaption,
        removeFile,
        filesReady,
        uploads,
        attachmentsOpen,
        setAttachmentsOpen,
        recordingVoice,
        setRecordingVoice,
        voiceTrigger,
        attachmentTrigger,
        fileInput,
        selection,
        uploadProgress,
        reply,
        setReply,
        unresolvedReply,
        replyError,
        retryReply,
        replyRetry,
        sending,
        checkingSend,
        sendError,
        setSendError,
        audienceReview,
        setAudienceReview,
        composerForm,
        pending,
        composerSession,
        recovery,
        recoveryEnabled,
        recoveryLocked,
        restoreError,
        retryRestore,
        locked,
        empty,
        online,
        send,
        loadIntoComposer,
        messagesChanged,
        reset,
        restore,
        focus,
        focusSoon,
    };
}

export type ThreadComposer = ReturnType<typeof useThreadComposer>;
