import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    ArrowLeft,
    Bookmark,
    Check,
    ChevronDown,
    Loader2,
    Mic,
    MoreHorizontal,
    Paperclip,
    Pencil,
    Pin,
    Reply,
    Search,
    Send,
    ShieldCheck,
    Smile,
    Star,
    Trash2,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type ComponentProps,
    type Ref,
} from 'react';
import { toast } from 'sonner';
import type {
    ChatEditDraft,
    ChatRemovalDraft,
    ChatUploadProgress,
} from './api';
import {
    ChatError,
    chatDraftKey,
    conversationPath,
    readChatDraft,
    readChatThreadMemory,
    readPendingChatSend,
    saveChatDraft,
    saveChatThreadMemory,
    savePendingChatSend,
} from './api';
import { useCallConversation } from './call-conversation-context';
import { chatActorRequest } from './chat-actor-request';
import { ChatAttachment } from './chat-attachment';
import {
    CHAT_FILE_ACCEPT,
    ChatAttachmentQueue,
    chatFileIssue,
} from './chat-attachment-queue';
import { ChatAudienceReview } from './chat-audience-review';
import { ChatCallbacks } from './chat-callbacks';
import { ChatCallButton } from './chat-calling';
import { ChatComposerRecovery } from './chat-composer-recovery';
import {
    chatComposerSession,
    forgetChatComposerSession,
    type ChatComposerSession,
    type ComposerRecoveryState,
} from './chat-composer-session';
import { ChatDetails } from './chat-details';
import { ChatFamilyWork } from './chat-family-work';
import { ChatMentionInput, ChatMessageText } from './chat-mention-input';
import { ChatMessageEditor } from './chat-message-editor';
import { ChatMessageRemoval } from './chat-message-removal';
import {
    chatReadingClass,
    chatSendingHint,
    useChatPreferences,
} from './chat-personal-preferences';
import type { PrivateChatDraft } from './chat-private-draft';
import { useChatRealtime } from './chat-realtime';
import { ChatResponseClock } from './chat-response-clock';
import {
    isServerChatFile,
    restoreChatFiles,
    type ChatSelectedFile,
} from './chat-selected-file';
import { registerChatSoundView } from './chat-sound-views';
import { applyChatChanges, readChatChanges } from './chat-sync';
import { watchChatUpdates } from './chat-update-watch';
import { ChatUrgentHelp } from './chat-urgent-contact';
import { ChatVoiceRecorder } from './chat-voice-recorder';
import { isChatVoiceNote } from './chat-voice-recording';
import type {
    ChatConversation,
    ChatHistory,
    ChatMention,
    ChatMessage,
    ChatPolicy,
} from './types';
import { useChatUploads } from './use-chat-uploads';

export type ChatThreadControls = { closeDetails: () => boolean };

export function ChatThread({
    inCall = false,
    ...props
}: ComponentProps<typeof ChatThreadContent> & { inCall?: boolean }) {
    const call = useCallConversation();
    if (
        !inCall &&
        call?.actorId === props.actorId &&
        call.conversationId === props.conversationId
    ) {
        return (
            <section className="flex h-full min-w-0 flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                <p className="font-semibold">
                    {call.mode === 'followup'
                        ? 'Conversation open in call follow-up'
                        : 'Conversation open in your call'}
                </p>
                <p className="text-sm text-muted-foreground">
                    Your draft stays with the conversation. Close this view to
                    continue here.
                </p>
                <Button
                    variant="outline"
                    className="min-h-[44px]"
                    onClick={call.returnToCall}
                >
                    {call.mode === 'followup'
                        ? 'Return to call follow-up'
                        : 'Return to call conversation'}
                </Button>
            </section>
        );
    }
    return (
        <ChatThreadContent key={props.actorId} {...props} compact={inCall} />
    );
}

function ChatThreadContent({
    conversationId,
    actorId,
    family = false,
    policy: listPolicy,
    onBack,
    onChange,
    initialMessage = null,
    handoffRequest = 0,
    familyInbox = false,
    compact = false,
    controlsRef,
}: {
    conversationId: number;
    actorId: number;
    family?: boolean;
    policy: ChatPolicy;
    onBack?: () => void;
    onChange?: () => void;
    initialMessage?: number | null;
    handoffRequest?: number;
    familyInbox?: boolean;
    compact?: boolean;
    controlsRef?: Ref<ChatThreadControls>;
}) {
    const [history, setHistory] = useState<ChatHistory | null>(null);
    const { values: personal } = useChatPreferences(actorId);
    const policy = history?.conversation.policy ?? listPolicy;
    const realtime = useChatRealtime();
    const realtimeRef = useRef(realtime);
    useEffect(() => {
        realtimeRef.current = realtime;
    }, [realtime]);
    const historyRef = useRef<ChatHistory | null>(null);
    historyRef.current = history;
    const historyAnchor = useRef<number | null>(null);
    const loadSequence = useRef(0);
    const invalidateLoads = useCallback(() => {
        ++loadSequence.current;
    }, []);
    const [failure, setFailure] = useState('');
    const [loading, setLoading] = useState(true);
    const [older, setOlder] = useState(false);
    const [query, setQueryState] = useState('');
    const [searchOpen, setSearchOpenState] = useState(false);
    const [searchResults, setSearchResults] = useState<ChatMessage[] | null>(
        null,
    );
    const [searchError, setSearchError] = useState('');
    const [searchBusy, setSearchBusy] = useState(false);
    const [searchRetry, setSearchRetry] = useState(0);
    const searchAuthority = useRef(0);
    const [atEnd, setAtEnd] = useState(true);
    const [draft, setDraft] = useState('');
    const [composerSession, setComposerSession] =
        useState<ChatComposerSession | null>(null);
    const [recovery, setRecovery] = useState<ComposerRecoveryState | null>(
        null,
    );
    const [restoreError, setRestoreError] = useState('');
    const [restoreRetry, setRestoreRetry] = useState(0);
    const [editing, setEditing] = useState<ChatEditDraft | null>(null);
    const [mentions, setMentions] = useState<ChatMention[]>([]);
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
    const [deleting, setDeleting] = useState<ChatRemovalDraft | null>(null);
    const [actionMessage, setActionMessage] = useState<ChatMessage | null>(
        null,
    );
    const [acting, setActing] = useState(false);
    const actionTrigger = useRef<HTMLButtonElement | null>(null);
    const detailsTrigger = useRef<HTMLButtonElement | null>(null);
    const [details, setDetails] = useState(false);
    useImperativeHandle(
        controlsRef,
        () => ({
            closeDetails: () => {
                if (!details) return false;
                setDetails(false);
                requestAnimationFrame(() => detailsTrigger.current?.focus());
                return true;
            },
        }),
        [details],
    );
    const [contextMessage, setContextMessage] = useState<number | null>(null);
    const contextRef = useRef<number | null>(null);
    const viewport = useRef<HTMLDivElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const mutation = useRef<string | null>(null);
    const pendingBody = useRef<FormData | null>(null);
    const active = useRef<number | null>(conversationId);
    const operations = useRef(new AbortController());
    useEffect(() => {
        active.current = conversationId;
        const controller = new AbortController();
        operations.current = controller;
        return () => {
            active.current = null;
            controller.abort();
        };
    }, [conversationId]);
    const key = chatDraftKey(
        actorId,
        history?.conversation.client?.id ?? null,
        conversationId,
    );
    const keyRef = useRef(key);
    keyRef.current = key;
    active.current = conversationId;
    const conversation = history?.conversation;
    const soundViewAvailable = Boolean(conversation) && !failure;
    useEffect(() => {
        if (!soundViewAvailable) return;
        return registerChatSoundView(actorId, conversationId);
    }, [actorId, conversationId, soundViewAvailable]);
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
    const restorePosition = useRef<{ id: number; offset: number } | null>(null);
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
    useEffect(() => {
        setSending(false);
        setActing(false);
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
    const setQuery = (value: string) => {
        setQueryState(value);
        saveChatThreadMemory(keyRef.current, { query: value });
    };
    const setSearchOpen = (value: boolean) => {
        setSearchOpenState(value);
        saveChatThreadMemory(keyRef.current, { searchOpen: value });
    };

    const load = useCallback(
        async (signal?: AbortSignal, refreshContext = false) => {
            const anchor = contextRef.current;
            // A context view still receives removals and access changes; it does not
            // append live messages or advance the user's read position.
            if (
                anchor !== null &&
                !refreshContext &&
                !historyRef.current?.sync_cursor
            )
                return;
            const id = conversationId;
            const sequence = ++loadSequence.current;
            const previous = historyRef.current;
            try {
                if (
                    previous?.sync_cursor &&
                    previous.conversation.id === id &&
                    historyAnchor.current === anchor
                ) {
                    try {
                        const changes = await readChatChanges(
                            actorId,
                            id,
                            previous.sync_cursor,
                            signal,
                        );
                        if (
                            active.current !== id ||
                            signal?.aborted ||
                            contextRef.current !== anchor ||
                            sequence !== loadSequence.current
                        )
                            return;
                        setHistory((current) =>
                            current
                                ? applyChatChanges(
                                      current,
                                      changes,
                                      anchor !== null,
                                  )
                                : current,
                        );
                        const updated = new Map(
                            changes.messages.map((message) => [
                                message.id,
                                message,
                            ]),
                        );
                        if (updated.size) {
                            ++searchAuthority.current;
                            window.dispatchEvent(
                                new Event('oblivion:chat-search-refresh'),
                            );
                        }
                        setSearchResults(
                            (current) =>
                                current
                                    ?.map(
                                        (message) =>
                                            updated.get(message.id) ?? message,
                                    )
                                    .filter((message) => !message.deleted) ??
                                null,
                        );
                        setReplyState((current) => {
                            if (!current) return current;
                            const replacement = updated.get(current.id);
                            if (replacement?.deleted) {
                                saveChatThreadMemory(keyRef.current, {
                                    replyId: null,
                                });
                                return null;
                            }
                            return replacement ?? current;
                        });
                        setActionMessage((current) =>
                            current && updated.has(current.id)
                                ? updated.get(current.id)!.deleted
                                    ? null
                                    : updated.get(current.id)!
                                : current,
                        );
                        setFailure('');
                        return changes.conversation;
                    } catch (error) {
                        if (
                            !(error instanceof ChatError) ||
                            error.code !== 'chat_sync_reset'
                        )
                            throw error;
                        if (
                            active.current !== id ||
                            signal?.aborted ||
                            contextRef.current !== anchor ||
                            sequence !== loadSequence.current
                        )
                            return;
                        // Do not retain the previous audience's messages or search results
                        // while loading the replacement authorised snapshot.
                        setHistory(null);
                        historyRef.current = null;
                        ++searchAuthority.current;
                        setSearchResults(null);
                    }
                }
                const next = await chatActorRequest<ChatHistory>(
                    actorId,
                    `${conversationPath(id)}/messages${anchor ? `?around=${anchor}` : ''}`,
                    { signal },
                );
                if (
                    active.current !== id ||
                    signal?.aborted ||
                    contextRef.current !== anchor ||
                    sequence !== loadSequence.current
                )
                    return;
                historyAnchor.current = anchor;
                setHistory((previous) => {
                    if (next.sync_cursor) return next;
                    if (anchor || !previous || previous.conversation.id !== id)
                        return next;
                    const earliest = next.messages[0]?.id ?? Infinity;
                    const retained = previous.messages.filter(
                        (message) => message.id < earliest,
                    );
                    return {
                        ...next,
                        has_more: retained.length
                            ? previous.has_more
                            : next.has_more,
                        messages: [...retained, ...next.messages],
                    };
                });
                setFailure('');
                return next.conversation;
            } catch (error) {
                if (
                    signal?.aborted ||
                    active.current !== id ||
                    contextRef.current !== anchor ||
                    sequence !== loadSequence.current
                )
                    return;
                if (
                    error instanceof ChatError &&
                    [401, 403, 404, 419].includes(error.status)
                ) {
                    forgetChatComposerSession(actorId, id);
                    setHistory(null);
                    historyRef.current = null;
                    ++searchAuthority.current;
                    setSearchResults(null);
                    setDeleting(null);
                    saveChatThreadMemory(keyRef.current, { removing: null });
                    if (anchor !== null) {
                        contextRef.current = null;
                        setContextMessage(null);
                        restorePosition.current = null;
                        saveChatThreadMemory(keyRef.current, {
                            readAnchor: null,
                            readOffset: 0,
                        });
                    }
                }
                setFailure(
                    error instanceof Error
                        ? error.message
                        : 'Chat could not connect.',
                );
            } finally {
                if (
                    active.current === id &&
                    !signal?.aborted &&
                    sequence === loadSequence.current
                )
                    setLoading(false);
            }
        },
        [conversationId, actorId],
    );

    useEffect(() => {
        const controller = new AbortController();
        setHistory(null);
        historyRef.current = null;
        historyAnchor.current = null;
        setLoading(true);
        setFailure('');
        setReplyState(null);
        setUnresolvedReply(null);
        setReplyError('');
        setFilesState([]);
        setFileMemoryKey(null);
        setQueryState('');
        setSearchResults(null);
        setSearchOpenState(false);
        restorePosition.current = null;
        contextRef.current = null;
        setContextMessage(null);
        setAtEnd(true);
        setSendError('');
        setAudienceReview(null);
        mutation.current = null;
        pendingBody.current = null;
        // The canonical client key is restored once the authorised projection arrives.
        setDraft('');
        setEditing(null);
        setDeleting(null);
        setMentions([]);
        const stopUpdates = watchChatUpdates(
            async () => Boolean(await load(controller.signal)),
            {
                interval: () => (realtimeRef.current === 'live' ? 30000 : 5000),
            },
        );
        return () => {
            controller.abort();
            stopUpdates();
            invalidateLoads();
        };
    }, [conversationId, load, invalidateLoads]);

    const hasConversation = Boolean(conversation);
    useEffect(() => {
        const controller = new AbortController();
        if (hasConversation) {
            setDraft(readChatDraft(key));
            const remembered = readChatThreadMemory(key);
            setEditing(remembered.editing ?? null);
            setDeleting(remembered.removing ?? null);
            setMentions(remembered.mentions);
            setFilesState(remembered.files);
            setFileCaptions(
                remembered.fileCaptions ?? remembered.files.map(() => ''),
            );
            setQueryState(remembered.query);
            setSearchOpenState(remembered.searchOpen);
            if (remembered.readAnchor) {
                restorePosition.current = {
                    id: remembered.readAnchor,
                    offset: remembered.readOffset,
                };
                contextRef.current = remembered.readAnchor;
                setContextMessage(remembered.readAnchor);
                setAtEnd(false);
                void load(undefined, true);
            }
            if (remembered.replyId) {
                replyRecovery.current = controller;
                setUnresolvedReply(remembered.replyId);
                setReplyError('');
                void chatActorRequest<ChatHistory>(
                    actorId,
                    `${conversationPath(conversationId)}/messages?around=${remembered.replyId}`,
                    { signal: controller.signal },
                )
                    .then((result) => {
                        if (controller.signal.aborted || keyRef.current !== key)
                            return;
                        const original = result.messages.find(
                            (message) =>
                                message.id === remembered.replyId &&
                                !message.deleted,
                        );
                        setReplyState(original ?? null);
                        setUnresolvedReply(null);
                        if (!original) {
                            saveChatThreadMemory(key, { replyId: null });
                            toast.error(
                                'The original reply is no longer available. Your draft and files have been kept.',
                            );
                        }
                    })
                    .catch(() => {
                        if (
                            !controller.signal.aborted &&
                            keyRef.current === key
                        )
                            setReplyError(
                                'Your previous reply could not be checked. Retry or remove the reply before sending.',
                            );
                    });
            }
            pendingBody.current = readPendingChatSend(key);
            if (pendingBody.current) {
                setFileCaptions(
                    pendingBody.current.getAll('captions[]').map(String),
                );
                if (pendingBody.current.has('files[]'))
                    setFilesState(
                        pendingBody.current
                            .getAll('files[]')
                            .filter(
                                (file): file is File => file instanceof File,
                            ),
                    );
                setSendError(
                    'A previous send has not been confirmed. Retry to check it safely.',
                );
            }
            setFileMemoryKey(key);
        }
        return () => {
            controller.abort();
        };
    }, [key, hasConversation, actorId, conversationId, load, replyRetry]);
    const recoveryEnabled = policy.draft_recovery_enabled === true;
    const legacyPending = !!pendingBody.current;
    const recoveryLocked =
        recoveryEnabled &&
        !pendingBody.current &&
        (!recovery ||
            recovery.busy ||
            recovery.choice !== 'ready' ||
            !!recovery.replacement ||
            !!recovery.send.reference ||
            !['idle', 'confirmed', 'cancelled'].includes(recovery.send.phase) ||
            !['ready', 'saving', 'uncertain', 'unavailable'].includes(
                recovery.draft.phase,
            ));
    const recoveryValue: PrivateChatDraft | null =
        draft || files.length || reply || unresolvedReply
            ? {
                  content: draft,
                  reply_to_id: reply?.id ?? unresolvedReply,
                  mentions: mentions.map(({ user_id, start, length }) => ({
                      user_id,
                      start,
                      length,
                  })),
                  uploads: files.flatMap((file, index) => {
                      const row = uploads.rows.find(
                          (item) =>
                              item.file === file &&
                              ['ready', 'uploaded', 'scanning'].includes(
                                  item.state,
                              ),
                      );
                      return row || isServerChatFile(file)
                          ? [
                                {
                                    id:
                                        row?.id ??
                                        (isServerChatFile(file)
                                            ? file.uploadId
                                            : ''),
                                    caption: fileCaptions[index] ?? '',
                                },
                            ]
                          : [];
                  }),
              }
            : null;
    const recoveryValueJson = JSON.stringify(recoveryValue);
    useEffect(() => {
        if (
            !recoveryEnabled ||
            !hasConversation ||
            fileMemoryKey !== key ||
            readPendingChatSend(key)
        )
            return;
        const memory = readChatThreadMemory(key);
        const content = readChatDraft(key);
        const initial =
            content || memory.files.length || memory.replyId
                ? {
                      content,
                      reply_to_id: memory.replyId,
                      mentions: memory.mentions.map(
                          ({ user_id, start, length }) => ({
                              user_id,
                              start,
                              length,
                          }),
                      ),
                      uploads: (memory.uploads ?? [])
                          .filter(
                              (row) =>
                                  memory.files.includes(row.file) &&
                                  ['ready', 'uploaded', 'scanning'].includes(
                                      row.state,
                                  ),
                          )
                          .map((row) => ({
                              id: row.id,
                              caption:
                                  memory.fileCaptions?.[
                                      memory.files.indexOf(row.file)
                                  ] ?? '',
                          })),
                  }
                : undefined;
        const session = chatComposerSession(
            {
                actor_id: actorId,
                conversation_id: conversationId,
                client_id: historyRef.current?.conversation.client?.id ?? null,
            },
            initial,
        );
        setComposerSession(session);
        setRecovery(session.snapshot());
        const stop = session.subscribe(() => setRecovery(session.snapshot()));
        void session.start();
        return stop;
    }, [
        actorId,
        conversationId,
        key,
        hasConversation,
        fileMemoryKey,
        recoveryEnabled,
        legacyPending,
    ]);
    useEffect(() => {
        if (
            !recoveryEnabled ||
            !composerSession ||
            recoveryLocked ||
            fileMemoryKey !== key ||
            pendingBody.current
        )
            return;
        composerSession.edit(
            JSON.parse(recoveryValueJson) as PrivateChatDraft | null,
        );
        if (composerSession.snapshot().draft.phase !== 'ready') return;
        const timer = window.setTimeout(() => void composerSession.save(), 800);
        return () => clearTimeout(timer);
    }, [
        composerSession,
        recoveryLocked,
        recoveryValueJson,
        fileMemoryKey,
        key,
        recovery?.draft.phase,
        recoveryEnabled,
    ]);
    const replacement = recovery?.replacement;
    useEffect(() => {
        if (!recoveryEnabled || !composerSession || fileMemoryKey !== key)
            return;
        if (
            !['scope_changed', 'revoked'].includes(
                recovery?.draft.phase ?? '',
            ) &&
            !['scope_changed', 'revoked'].includes(recovery?.send.phase ?? '')
        )
            return;
        // Audience/access loss must also remove the old visible composer, not only its server projection.
        saveChatDraft(key, '');
        saveChatThreadMemory(key, {
            mentions: [],
            files: [],
            fileCaptions: [],
            replyId: null,
        });
        setDraft('');
        setMentions([]);
        setFilesState([]);
        setFileCaptions([]);
        setReplyState(null);
        setUnresolvedReply(null);
        replyRecovery.current?.abort();
    }, [
        recoveryEnabled,
        composerSession,
        fileMemoryKey,
        key,
        recovery?.draft.phase,
        recovery?.send.phase,
    ]);
    useEffect(() => {
        if (!replacement || !composerSession || fileMemoryKey !== key) return;
        const controller = new AbortController();
        setRestoreError('');
        void (async () => {
            try {
                const value = replacement.value;
                const restored = await restoreChatFiles(
                    actorId,
                    conversationId,
                    value?.uploads.map((upload) => upload.id) ?? [],
                    controller.signal,
                );
                if (controller.signal.aborted || keyRef.current !== key) return;
                const chars = [...(value?.content ?? '')];
                const restoredMentions =
                    value?.mentions.map((mention) => ({
                        ...mention,
                        label: chars
                            .slice(
                                mention.start,
                                mention.start + mention.length,
                            )
                            .join(''),
                    })) ?? [];
                saveChatDraft(key, value?.content ?? '');
                saveChatThreadMemory(key, {
                    mentions: restoredMentions,
                    files: restored,
                    fileCaptions:
                        value?.uploads.map((upload) => upload.caption) ?? [],
                    replyId: value?.reply_to_id ?? null,
                });
                setDraft(value?.content ?? '');
                setMentions(restoredMentions);
                setFilesState(restored);
                setFileCaptions(
                    value?.uploads.map((upload) => upload.caption) ?? [],
                );
                setReplyState(null);
                setUnresolvedReply(value?.reply_to_id ?? null);
                setReplyRetry((value) => value + 1);
                composerSession.acknowledgeReplacement(replacement.revision);
                requestAnimationFrame(() => {
                    if (keyRef.current === key)
                        composerForm.current
                            ?.querySelector<HTMLTextAreaElement>('textarea')
                            ?.focus();
                });
            } catch (error) {
                if (!controller.signal.aborted)
                    setRestoreError((error as Error).message);
            }
        })();
        return () => controller.abort();
    }, [
        replacement,
        composerSession,
        fileMemoryKey,
        key,
        actorId,
        conversationId,
        restoreRetry,
    ]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (
                recovery?.draft.dirty ||
                recovery?.draft.phase === 'saving' ||
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
    }, [recovery, files, uploads.rows]);
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
    const latestId = history?.messages.at(-1)?.id;
    const unreadMarker = history?.conversation.unread_marker;
    // The first unread message is fixed when the conversation opens, so the
    // "New messages" divider stays put after the read receipt clears the count.
    const [firstUnreadId, setFirstUnreadId] = useState<
        number | null | undefined
    >(undefined);
    const [unseenBelow, setUnseenBelow] = useState(false);
    if (history && firstUnreadId === undefined) {
        const unread = history.conversation.unread;
        const fromOthers = history.messages.filter((message) => !message.mine);
        const first =
            unread > 0 && unread <= fromOthers.length
                ? fromOthers[fromOthers.length - unread].id
                : null;
        setFirstUnreadId(first);
        if (first !== null) setUnseenBelow(true);
    }
    if (atEnd && unseenBelow) setUnseenBelow(false);
    // Announce only messages from others that arrive after opening, never
    // the history that was already there.
    const announcedThrough = useRef<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    useEffect(() => {
        const list = history?.messages;
        if (!list?.length || !latestId) return;
        const previous = announcedThrough.current;
        announcedThrough.current = Math.max(previous ?? 0, latestId);
        if (previous === null) return;
        const arrived = list.filter(
            (message) =>
                message.id > previous && !message.mine && !message.deleted,
        );
        if (!arrived.length) return;
        setAnnouncement(
            arrived.length === 1
                ? `New message from ${arrived[0].sender_name}`
                : `${arrived.length} new messages`,
        );
        if (!atEnd) setUnseenBelow(true);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- runs per new latest message only
    }, [latestId]);
    useEffect(() => {
        if (atEnd)
            viewport.current?.scrollTo({ top: viewport.current.scrollHeight });
    }, [latestId, atEnd, sending, checkingSend]);
    useEffect(() => {
        const remembered = restorePosition.current;
        const el = viewport.current;
        if (!remembered || !el || atEnd || searchResults) return;
        const anchor = el.querySelector<HTMLElement>(
            `[data-chat-message="${remembered.id}"]`,
        );
        if (!anchor) return;
        el.scrollTop +=
            anchor.getBoundingClientRect().top -
            el.getBoundingClientRect().top -
            remembered.offset;
        restorePosition.current = null;
    }, [history, atEnd, searchResults]);
    useEffect(() => {
        if (
            !latestId ||
            !atEnd ||
            searchResults ||
            failure ||
            contextMessage ||
            history?.has_more_changes
        )
            return;
        let stopped = false;
        const mark = () => {
            if (document.visibilityState === 'visible' && document.hasFocus()) {
                void chatActorRequest(
                    actorId,
                    `${conversationPath(conversationId)}/read`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            message_id: latestId,
                            unread_marker: unreadMarker ?? null,
                        }),
                    },
                )
                    .then(() => {
                        if (stopped) return;
                        onChange?.();
                        window.dispatchEvent(
                            new Event('oblivion:chat-unread-changed'),
                        );
                    })
                    .catch(() => undefined);
            }
        };
        const timer = window.setTimeout(mark, 700);
        window.addEventListener('focus', mark);
        document.addEventListener('visibilitychange', mark);
        return () => {
            stopped = true;
            clearTimeout(timer);
            window.removeEventListener('focus', mark);
            document.removeEventListener('visibilitychange', mark);
        };
    }, [
        actorId,
        conversationId,
        latestId,
        atEnd,
        searchResults,
        failure,
        onChange,
        contextMessage,
        unreadMarker,
        history?.has_more_changes,
    ]);

    const jumpToMessage = useCallback(
        async (messageId: number) => {
            const id = conversationId;
            contextRef.current = messageId;
            setContextMessage(messageId);
            setAtEnd(false);
            try {
                const next = await chatActorRequest<ChatHistory>(
                    actorId,
                    `${conversationPath(id)}/messages?around=${messageId}`,
                );
                if (active.current !== id || contextRef.current !== messageId)
                    return;
                historyAnchor.current = messageId;
                setHistory(next);
                setSearchResults(null);
                setSearchOpenState(false);
                setQueryState('');
                saveChatThreadMemory(keyRef.current, {
                    searchOpen: false,
                    query: '',
                    readAnchor: messageId,
                    readOffset: 0,
                });
                requestAnimationFrame(() => {
                    if (
                        active.current !== id ||
                        contextRef.current !== messageId
                    )
                        return;
                    const target = viewport.current?.querySelector<HTMLElement>(
                        `[data-chat-message="${messageId}"]`,
                    );
                    target?.scrollIntoView({ block: 'center' });
                    target?.focus({ preventScroll: true });
                });
            } catch (error) {
                if (active.current === id && contextRef.current === messageId) {
                    contextRef.current = null;
                    setContextMessage(null);
                    toast.error((error as Error).message);
                }
            }
        },
        [actorId, conversationId],
    );

    const openedMessage = useRef<number | null>(null);
    useEffect(() => {
        if (
            !hasConversation ||
            !initialMessage ||
            openedMessage.current === initialMessage
        )
            return;
        openedMessage.current = initialMessage;
        void jumpToMessage(initialMessage);
    }, [hasConversation, initialMessage, jumpToMessage]);

    useEffect(() => {
        setSearchError('');
        setSearchBusy(false);
        if (!hasConversation || !searchOpen || query.trim().length < 2) {
            setSearchResults(null);
            return;
        }
        setSearchResults([]);
        setSearchBusy(true);
        const controller = new AbortController();
        let stopUpdates: (() => void) | undefined;
        const timer = window.setTimeout(() => {
            stopUpdates = watchChatUpdates(
                async () => {
                    const authority = searchAuthority.current;
                    try {
                        const result = await chatActorRequest<ChatHistory>(
                            actorId,
                            `${conversationPath(conversationId)}/messages?q=${encodeURIComponent(query.trim())}`,
                            { signal: controller.signal },
                        );
                        if (
                            controller.signal.aborted ||
                            authority !== searchAuthority.current
                        )
                            return false;
                        setSearchResults(result.messages);
                        setSearchError('');
                        return true;
                    } catch (error) {
                        if (
                            controller.signal.aborted ||
                            authority !== searchAuthority.current
                        )
                            return false;
                        if (
                            error instanceof ChatError &&
                            [401, 403, 404, 419].includes(error.status)
                        ) {
                            ++searchAuthority.current;
                            invalidateLoads();
                            setSearchResults(null);
                            setHistory(null);
                            historyRef.current = null;
                            setFailure(error.message);
                        } else {
                            setSearchError((error as Error).message);
                        }
                        return false;
                    } finally {
                        if (
                            !controller.signal.aborted &&
                            authority === searchAuthority.current
                        )
                            setSearchBusy(false);
                    }
                },
                {
                    interval: () =>
                        realtimeRef.current === 'live' ? 30000 : 10000,
                    events: [
                        'oblivion:chat-refresh',
                        'oblivion:chat-search-refresh',
                    ],
                },
            );
        }, 250);
        return () => {
            controller.abort();
            stopUpdates?.();
            clearTimeout(timer);
        };
    }, [
        query,
        searchOpen,
        conversationId,
        actorId,
        hasConversation,
        searchRetry,
        invalidateLoads,
    ]);

    async function loadOlder() {
        if (!history?.messages[0] || older) return;
        const id = conversationId;
        const anchor = contextRef.current;
        const cursor = history.sync_cursor;
        setOlder(true);
        const height = viewport.current?.scrollHeight ?? 0;
        try {
            const next = await chatActorRequest<ChatHistory>(
                actorId,
                `${conversationPath(id)}/messages?before=${history.messages[0].id}`,
            );
            if (
                active.current !== id ||
                contextRef.current !== anchor ||
                historyRef.current?.sync_cursor !== cursor
            )
                return;
            setHistory((current) =>
                current && current.sync_cursor === cursor
                    ? {
                          ...current,
                          has_more: next.has_more,
                          messages: [
                              ...next.messages,
                              ...current.messages.filter(
                                  (message) =>
                                      !next.messages.some(
                                          (m) => m.id === message.id,
                                      ),
                              ),
                          ],
                      }
                    : current,
            );
            requestAnimationFrame(() => {
                if (viewport.current)
                    viewport.current.scrollTop +=
                        viewport.current.scrollHeight - height;
            });
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setOlder(false);
        }
    }

    async function send(checkOnly = false) {
        if (unresolvedReply && !pendingBody.current) return;
        if (
            sendRequest.current ||
            sending ||
            (!draft.trim() && !files.length && !pendingBody.current)
        )
            return;
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
            pendingBody.current = body;
            savePendingChatSend(submittedKey, body);
        }
        setSending(true);
        setCheckingSend(retrying);
        setUploadProgress(null);
        setSendError('');
        const controller = new AbortController();
        sendRequest.current = controller;
        try {
            let confirmed = false;
            if (retrying) {
                const reference = String(
                    pendingBody.current.get('mutation_id'),
                );
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
                        body: pendingBody.current,
                        signal: controller.signal,
                        onUploadProgress: pendingBody.current.has('files[]')
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
            setDraft('');
            setMentions([]);
            setFiles([]);
            setReply(null);
            pendingBody.current = null;
            mutation.current = null;
            setAtEnd(true);
            contextRef.current = null;
            setContextMessage(null);
            await load(undefined, true);
            onChange?.();
        } catch (error) {
            if (keyRef.current === submittedKey && !controller.signal.aborted) {
                if (
                    error instanceof ChatError &&
                    error.code === 'chat_audience_review_required'
                ) {
                    // The server checked the send reference and confirms this write did not happen.
                    pendingBody.current = null;
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
                    pendingBody.current = null;
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

    async function action(
        message: ChatMessage,
        action: 'save' | 'pin' | 'react',
        emoji?: string,
    ) {
        if (acting) return;
        const id = conversationId;
        const signal = operations.current.signal;
        setActing(true);
        try {
            await chatActorRequest(
                actorId,
                `/chat/api/messages/${message.id}/action`,
                {
                    method: 'POST',
                    signal,
                    body: JSON.stringify({
                        action,
                        emoji,
                        active:
                            action === 'pin'
                                ? !message.pinned
                                : action === 'save'
                                  ? !message.saved
                                  : !message.reactions.some(
                                        (reaction) =>
                                            reaction.emoji === emoji &&
                                            reaction.mine,
                                    ),
                    }),
                },
            );
            if (signal.aborted || active.current !== id) return;
            setActionMessage(null);
            await load(undefined, true);
        } catch (error) {
            if (!signal.aborted) toast.error((error as Error).message);
        } finally {
            if (!signal.aborted) setActing(false);
        }
    }
    async function preference(
        name: 'is_muted' | 'is_favourite',
        value: boolean,
    ) {
        const signal = operations.current.signal;
        try {
            await chatActorRequest(
                actorId,
                `${conversationPath(conversationId)}/preferences`,
                {
                    method: 'PATCH',
                    signal,
                    body: JSON.stringify({ [name]: value }),
                },
            );
            if (signal.aborted) return;
            await load(undefined, true);
            if (!signal.aborted) onChange?.();
        } catch (error) {
            if (!signal.aborted) toast.error((error as Error).message);
        }
    }
    if (loading)
        return (
            <div
                className="flex h-full items-center justify-center"
                role="status"
            >
                <LoadingState message="Opening conversation…" />
            </div>
        );
    if (!conversation)
        return (
            <div className="m-auto max-w-sm space-y-3 p-6 text-center">
                <h2 className="font-semibold">Conversation unavailable</h2>
                <p className="text-sm text-muted-foreground">
                    {failure || 'Choose another conversation.'}
                </p>
                <Button
                    variant="outline"
                    onClick={() => void load(undefined, true)}
                >
                    Try again
                </Button>
            </div>
        );
    const messages = searchResults ?? history.messages;
    const familyWork = !family && conversation.type === 'family' && (
        <ChatFamilyWork
            inbox={familyInbox}
            handoffRequest={handoffRequest}
            actorId={actorId}
            conversationId={conversationId}
            clientName={conversation.client?.name ?? conversation.title}
            onChange={() => {
                void load(undefined, true);
                onChange?.();
            }}
        />
    );

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-1">
            <section
                className={cn(
                    'h-full min-h-0 min-w-0 flex-1 flex-col bg-background',
                    details ? (compact ? 'hidden' : 'hidden md:flex') : 'flex',
                )}
                aria-label={`Conversation: ${conversation.title}`}
            >
                {familyInbox && familyWork}
                <header className="flex min-h-16 shrink-0 items-center gap-1 border-b px-2 py-2 sm:min-h-20 sm:gap-3 sm:px-4 sm:py-3">
                    {onBack && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-[44px] shrink-0 md:hidden"
                            aria-label="Back to conversations"
                            onClick={onBack}
                        >
                            <ArrowLeft />
                        </Button>
                    )}
                    <div className="hidden size-[44px] shrink-0 items-center justify-center rounded-2xl bg-primary/10 font-semibold text-primary sm:flex">
                        {conversation.title.slice(0, 2).toUpperCase()}
                    </div>
                    <Button
                        ref={detailsTrigger}
                        variant="ghost"
                        type="button"
                        className="h-auto min-h-[44px] min-w-0 flex-1 flex-col items-start gap-0 rounded px-1 text-left focus-visible:outline-2 focus-visible:outline-primary [&>*]:max-w-full"
                        onClick={() => setDetails(true)}
                    >
                        <h2 className="truncate font-semibold">
                            {conversation.title || 'Conversation'}
                        </h2>
                        <p className="truncate text-xs text-muted-foreground">
                            {conversation.client?.name ??
                                `${conversation.participants.length} participants`}{' '}
                            · View people
                        </p>
                    </Button>
                    <ChatCallButton
                        conversation={conversation}
                        actorId={actorId}
                        family={family}
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-[44px]"
                        aria-label="Search this conversation"
                        onClick={() => setSearchOpen(!searchOpen)}
                    >
                        <Search className="size-5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="hidden size-[44px] sm:inline-flex"
                        aria-label={
                            conversation.favourite
                                ? 'Remove favourite'
                                : 'Favourite conversation'
                        }
                        aria-pressed={conversation.favourite}
                        onClick={() =>
                            void preference(
                                'is_favourite',
                                !conversation.favourite,
                            )
                        }
                    >
                        <Star
                            className={cn(
                                'size-5',
                                conversation.favourite &&
                                    'fill-current text-status-warning',
                            )}
                        />
                    </Button>
                </header>
                {conversation.type === 'family' && (
                    <div className="flex shrink-0 gap-2 border-b bg-status-info-bg px-4 py-2.5 text-xs text-status-info">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                        <div>
                            {conversation.care_team_cover
                                ? 'The named worker and authorised care-team cover can see and respond to this conversation.'
                                : 'Only the invited family members and authorised staff participants can see this conversation.'}
                            {family && (
                                <p className="mt-1">
                                    <ChatResponseClock
                                        audience="family"
                                        clock={
                                            conversation.response_clock ??
                                            undefined
                                        }
                                        wording={policy.response_expectation}
                                    />
                                    <span>Chat is not for emergencies.</span>
                                </p>
                            )}
                            {conversation.client && (
                                <div className="mt-2">
                                    <ChatUrgentHelp
                                        actorId={actorId}
                                        clientId={conversation.client.id}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                )}
                {!familyInbox && familyWork}
                {conversation.type === 'family' && (!familyInbox || family) && (
                    <div className="flex shrink-0 justify-end border-b px-3 py-1">
                        <ChatCallbacks
                            conversationId={conversationId}
                            actorId={actorId}
                            family={family}
                        />
                    </div>
                )}
                {searchOpen && (
                    <div className="flex shrink-0 gap-2 border-b p-3">
                        <Input
                            autoFocus
                            aria-label="Search messages"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search this conversation…"
                        />
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Close search"
                            className="size-[44px] shrink-0"
                            onClick={() => {
                                setSearchOpen(false);
                                setQuery('');
                                requestAnimationFrame(() =>
                                    composerForm.current
                                        ?.querySelector('textarea')
                                        ?.focus(),
                                );
                            }}
                        >
                            <X />
                        </Button>
                    </div>
                )}
                {searchOpen && searchError && (
                    <div
                        role="status"
                        className="flex items-center justify-between gap-2 bg-status-warning-bg px-4 py-2 text-sm text-status-warning"
                    >
                        <span>Search updates paused. {searchError}</span>
                        <Button
                            variant="ghost"
                            className="min-h-[44px]"
                            onClick={() => setSearchRetry((value) => value + 1)}
                        >
                            Retry search
                        </Button>
                    </div>
                )}
                {searchOpen && searchBusy && (
                    <p
                        role="status"
                        className="flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground"
                    >
                        <Loader2
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Searching messages…
                    </p>
                )}
                {(failure || realtime === 'recovering') && (
                    <div
                        role="status"
                        className="flex items-center justify-between bg-status-warning-bg px-4 py-2 text-sm text-status-warning"
                    >
                        {failure
                            ? `Updates paused. ${failure}`
                            : 'Live updates reconnecting. Checking for new messages.'}
                        <Button
                            variant="ghost"
                            onClick={() => void load(undefined, true)}
                        >
                            Retry
                        </Button>
                    </div>
                )}
                {!failure && history.has_more_changes && (
                    <div
                        role="status"
                        className="flex items-center gap-2 bg-muted px-4 py-2 text-sm text-muted-foreground"
                    >
                        <Loader2
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Catching up on messages…
                    </div>
                )}
                <p className="sr-only" role="status" aria-live="polite">
                    {announcement}
                </p>
                <div
                    ref={viewport}
                    role="log"
                    aria-label="Messages"
                    aria-live="off"
                    className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 sm:px-6"
                    onScroll={() => {
                        const el = viewport.current;
                        if (!el || restorePosition.current) return;
                        const nearEnd =
                            el.scrollHeight - el.scrollTop - el.clientHeight <
                                60 && contextRef.current === null;
                        setAtEnd(nearEnd);
                        if (searchOpen) return;
                        const top = el.getBoundingClientRect().top;
                        const anchor = Array.from(
                            el.querySelectorAll<HTMLElement>(
                                '[data-chat-message]',
                            ),
                        ).find(
                            (message) =>
                                message.getBoundingClientRect().bottom > top,
                        );
                        saveChatThreadMemory(keyRef.current, {
                            readAnchor: nearEnd
                                ? null
                                : anchor
                                  ? Number(anchor.dataset.chatMessage)
                                  : contextRef.current,
                            readOffset:
                                nearEnd || !anchor
                                    ? 0
                                    : anchor.getBoundingClientRect().top - top,
                        });
                    }}
                >
                    {history.has_more && !searchResults && (
                        <div className="mb-4 text-center">
                            <Button
                                variant="outline"
                                onClick={() => void loadOlder()}
                                disabled={older}
                            >
                                {older ? 'Loading…' : 'Load earlier messages'}
                            </Button>
                        </div>
                    )}
                    {!messages.length && !searchBusy && !searchError && (
                        <div className="mx-auto max-w-sm py-14 text-center">
                            <h3 className="font-semibold">
                                {searchResults
                                    ? 'No matching messages'
                                    : 'Start the conversation'}
                            </h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {searchResults
                                    ? 'Try a different word or phrase.'
                                    : 'Send a message below. Files stay in this conversation until separately published with permission.'}
                            </p>
                        </div>
                    )}
                    {contextMessage && (
                        <Button
                            variant="outline"
                            className="mb-3 min-h-[44px]"
                            onClick={() => {
                                saveChatThreadMemory(keyRef.current, {
                                    readAnchor: null,
                                    readOffset: 0,
                                });
                                restorePosition.current = null;
                                contextRef.current = null;
                                setContextMessage(null);
                                setAtEnd(true);
                                setHistory(null);
                                setLoading(true);
                                void load(undefined, true);
                            }}
                        >
                            Return to latest messages
                        </Button>
                    )}
                    {messages.map((message, index) => (
                        <div
                            key={message.id}
                            id={`chat-message-${message.id}`}
                            data-chat-message={message.id}
                            tabIndex={-1}
                            className={cn(
                                'focus-visible:outline-2 focus-visible:outline-primary',
                                contextMessage === message.id &&
                                    'rounded-xl ring-2 ring-primary/30',
                            )}
                        >
                            {(index === 0 ||
                                formatDate(messages[index - 1].created_at) !==
                                    formatDate(message.created_at)) && (
                                <p className="my-5 text-center text-xs font-medium text-muted-foreground">
                                    {formatDate(message.created_at)}
                                </p>
                            )}
                            {!searchResults && message.id === firstUnreadId && (
                                <div
                                    role="separator"
                                    aria-label="New messages"
                                    className="my-4 flex items-center gap-3 text-xs font-semibold text-primary"
                                >
                                    <span className="h-px flex-1 bg-primary/30" />
                                    New messages
                                    <span className="h-px flex-1 bg-primary/30" />
                                </div>
                            )}
                            <article
                                className={cn(
                                    'group flex flex-col',
                                    personal.spacing === 'compact'
                                        ? 'mb-2'
                                        : 'mb-4',
                                    message.mine ? 'items-end' : 'items-start',
                                )}
                            >
                                <p className="mb-1 px-1 text-xs font-medium text-muted-foreground">
                                    {message.mine ? 'You' : message.sender_name}
                                </p>
                                <div
                                    className={cn(
                                        'max-w-[92%] rounded-2xl border sm:max-w-[78%]',
                                        personal.spacing === 'compact'
                                            ? 'px-3 py-2'
                                            : 'px-4 py-3',
                                        message.mine
                                            ? 'rounded-tr-md border-primary/10 bg-primary/10'
                                            : 'rounded-tl-md bg-muted/40',
                                    )}
                                >
                                    {message.pinned && (
                                        <p className="mb-2 flex items-center gap-1 text-xs font-medium">
                                            <Pin className="size-3" />
                                            Pinned
                                        </p>
                                    )}
                                    {!message.deleted &&
                                        message.reply_unavailable && (
                                            <p className="mb-2 rounded-lg border-l-2 px-3 py-2 text-xs text-muted-foreground">
                                                Earlier message not shared with
                                                you.
                                            </p>
                                        )}
                                    {!message.deleted &&
                                        message.reply_to_id && (
                                            <Button
                                                variant="ghost"
                                                disabled={!message.reply}
                                                aria-label={
                                                    message.reply
                                                        ? `View original message from ${message.reply.sender_name}`
                                                        : 'Original message unavailable'
                                                }
                                                className="mb-2 h-auto min-h-[44px] max-w-full flex-col items-start gap-1 border-l-2 border-primary/40 px-2 text-left text-xs whitespace-normal text-muted-foreground"
                                                onClick={() =>
                                                    message.reply &&
                                                    void jumpToMessage(
                                                        message.reply.id,
                                                    )
                                                }
                                            >
                                                <strong>
                                                    {message.reply
                                                        ?.sender_name ??
                                                        'Original message unavailable'}
                                                </strong>
                                                <span className="line-clamp-2">
                                                    {message.reply?.deleted
                                                        ? 'Message removed'
                                                        : message.reply
                                                              ?.content ||
                                                          'Attachment'}
                                                </span>
                                            </Button>
                                        )}
                                    <p
                                        className={cn(
                                            'leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-wrap',
                                            chatReadingClass(
                                                personal.text_size,
                                            ),
                                            message.deleted &&
                                                'text-muted-foreground italic',
                                        )}
                                    >
                                        {message.deleted ? (
                                            'Message removed'
                                        ) : (
                                            <ChatMessageText
                                                content={message.content}
                                                mentions={message.mentions}
                                                actorId={actorId}
                                            />
                                        )}
                                    </p>
                                    {message.attachments.map((file) => (
                                        <ChatAttachment
                                            key={file.id}
                                            file={file}
                                            messageId={message.id}
                                            conversationId={conversationId}
                                            actorId={actorId}
                                        />
                                    ))}
                                    {message.legacy_attachments.map(
                                        (file, i) => (
                                            <p
                                                key={i}
                                                className="mt-2 text-xs text-muted-foreground"
                                            >
                                                {file.url ? (
                                                    <a
                                                        href={file.url}
                                                        className="inline-flex min-h-[44px] items-center underline underline-offset-4"
                                                    >
                                                        {file.name} · Open
                                                        attachment
                                                    </a>
                                                ) : (
                                                    `${file.name} · Attachment no longer available`
                                                )}
                                            </p>
                                        ),
                                    )}
                                    <p className="mt-2 flex items-center justify-end gap-1 text-[11px] text-muted-foreground">
                                        <time
                                            dateTime={message.created_at}
                                            title={formatDateTime(
                                                message.created_at,
                                            )}
                                        >
                                            {formatTime(message.created_at)}
                                        </time>
                                        {message.edited_at && ' · edited'}
                                        {message.mine && (
                                            <>
                                                <Check
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                                <span className="sr-only">
                                                    Sent
                                                </span>
                                            </>
                                        )}
                                    </p>
                                </div>
                                {!message.deleted && (
                                    <div className="flex max-w-full flex-wrap items-center gap-1 px-1">
                                        {searchResults && (
                                            <Button
                                                variant="outline"
                                                className="min-h-[44px] text-xs"
                                                onClick={() =>
                                                    void jumpToMessage(
                                                        message.id,
                                                    )
                                                }
                                            >
                                                View in conversation
                                            </Button>
                                        )}
                                        {message.reactions.map((reaction) => (
                                            <Button
                                                variant="ghost"
                                                type="button"
                                                key={reaction.emoji}
                                                aria-pressed={reaction.mine}
                                                title={
                                                    reaction.people?.length
                                                        ? reaction.people.join(
                                                              ', ',
                                                          )
                                                        : undefined
                                                }
                                                className={cn(
                                                    'min-h-[44px] min-w-[44px] rounded-full px-2 text-xs',
                                                    reaction.mine &&
                                                        'bg-primary-fill/10 text-primary',
                                                )}
                                                onClick={() =>
                                                    void action(
                                                        message,
                                                        'react',
                                                        reaction.emoji,
                                                    )
                                                }
                                            >
                                                {reaction.emoji}{' '}
                                                {reaction.count}
                                            </Button>
                                        ))}
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-[44px] text-muted-foreground"
                                            aria-label={`Actions for message from ${message.sender_name} at ${formatTime(message.created_at)}`}
                                            onClick={(event) => {
                                                actionTrigger.current =
                                                    event.currentTarget;
                                                setActionMessage(message);
                                            }}
                                        >
                                            <MoreHorizontal className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </article>
                        </div>
                    ))}
                    {!searchOpen &&
                        !contextMessage &&
                        pendingBody.current &&
                        !history.messages.some(
                            (message) =>
                                message.mine &&
                                message.mutation_id ===
                                    pendingBody.current?.get('mutation_id'),
                        ) && (
                            <div
                                role="group"
                                aria-label="Pending outgoing message"
                                className="mb-4 ml-auto w-fit max-w-[90%] space-y-2 rounded-2xl border border-dashed border-primary/40 bg-primary/5 px-4 py-3 sm:max-w-[75%]"
                            >
                                <p className="text-xs font-medium">You</p>
                                <p className="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap">
                                    {String(
                                        pendingBody.current.get('content') ??
                                            '',
                                    )}
                                </p>
                                {files.length > 0 && (
                                    <ul
                                        aria-label="Pending message attachments"
                                        className="max-h-28 overflow-y-auto text-xs"
                                    >
                                        {files.map((file, index) => (
                                            <li
                                                key={`${file.name}:${index}`}
                                                className="py-1 [overflow-wrap:anywhere]"
                                            >
                                                {file.name}
                                                {fileCaptions[index] && (
                                                    <p className="text-muted-foreground">
                                                        {fileCaptions[index]}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                    {sending && (
                                        <Loader2
                                            className="size-3 shrink-0 animate-spin"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {checkingSend
                                        ? 'Checking whether it sent…'
                                        : sending
                                          ? 'Sending…'
                                          : 'Not confirmed · Use the send check below'}
                                </p>
                            </div>
                        )}
                </div>
                {!atEnd && !searchResults && (
                    <div className="relative h-0">
                        <Button
                            className="absolute right-4 bottom-3 min-h-[44px] shadow-lg"
                            size="sm"
                            onClick={() => setAtEnd(true)}
                        >
                            <ChevronDown className="size-4" />
                            {unseenBelow ? 'New messages' : 'Latest messages'}
                        </Button>
                    </div>
                )}
                <footer
                    hidden={searchOpen}
                    className="max-h-[55%] shrink-0 overflow-y-auto overscroll-contain border-t bg-background p-3 sm:p-4"
                >
                    {editing ? (
                        <ChatMessageEditor
                            actorId={actorId}
                            key={`${key}:${editing.messageId}`}
                            memoryKey={key}
                            conversationId={conversationId}
                            initial={editing}
                            people={conversation.participants.filter(
                                (person) =>
                                    person.id !== actorId &&
                                    conversation.mentionable_ids?.includes(
                                        person.id,
                                    ),
                            )}
                            canSend={conversation.can_send && policy.enabled}
                            onClose={() => {
                                setEditing(null);
                                requestAnimationFrame(() =>
                                    composerForm.current
                                        ?.querySelector('textarea')
                                        ?.focus(),
                                );
                            }}
                            onSaved={(message) => {
                                setEditing(null);
                                setHistory((current) =>
                                    current
                                        ? {
                                              ...current,
                                              messages: current.messages.map(
                                                  (item) =>
                                                      item.id === message.id
                                                          ? message
                                                          : item,
                                              ),
                                          }
                                        : current,
                                );
                                void load(undefined, true);
                                onChange?.();
                                requestAnimationFrame(() =>
                                    composerForm.current
                                        ?.querySelector('textarea')
                                        ?.focus(),
                                );
                            }}
                        />
                    ) : (
                        <>
                            {composerSession &&
                                recovery &&
                                !pendingBody.current && (
                                    <ChatComposerRecovery
                                        session={composerSession}
                                        state={recovery}
                                        onReturnFocus={() =>
                                            composerForm.current
                                                ?.querySelector<HTMLTextAreaElement>(
                                                    'textarea',
                                                )
                                                ?.focus()
                                        }
                                    />
                                )}
                            {restoreError && (
                                <div
                                    role="alert"
                                    className="mb-2 rounded-lg border p-3 text-sm"
                                >
                                    <p>{restoreError}</p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="mt-2 min-h-[44px]"
                                        onClick={() =>
                                            setRestoreRetry(
                                                (value) => value + 1,
                                            )
                                        }
                                    >
                                        Retry restoring draft
                                    </Button>
                                </div>
                            )}
                            {reply && (
                                <div className="mb-2 flex items-center gap-2 rounded-lg border-l-2 border-primary bg-muted/40 p-2 text-xs">
                                    <div className="min-w-0 flex-1">
                                        <strong>
                                            Replying to {reply.sender_name}
                                        </strong>
                                        <p className="truncate">
                                            {reply.content}
                                        </p>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-[44px] shrink-0"
                                        aria-label="Cancel reply"
                                        disabled={recoveryLocked}
                                        onClick={() => setReply(null)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            )}
                            {unresolvedReply && !pendingBody.current && (
                                <div className="mb-2 rounded-lg border p-3 text-sm">
                                    <p role={replyError ? 'alert' : 'status'}>
                                        {replyError ||
                                            'Checking your previous reply…'}
                                    </p>
                                    <div className="mt-2 flex gap-2">
                                        {replyError && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-[44px]"
                                                onClick={() =>
                                                    setReplyRetry(
                                                        (value) => value + 1,
                                                    )
                                                }
                                            >
                                                Retry reply
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="min-h-[44px]"
                                            disabled={recoveryLocked}
                                            onClick={() => setReply(null)}
                                        >
                                            Remove reply
                                        </Button>
                                    </div>
                                </div>
                            )}
                            {recordingVoice &&
                                conversation.can_send &&
                                policy.enabled &&
                                policy.files_enabled &&
                                policy.voice_notes_enabled &&
                                policy.voice_notes_available &&
                                !sending &&
                                !pendingBody.current && (
                                    <ChatVoiceRecorder
                                        key={`voice:${key}`}
                                        maxMb={policy.max_file_mb}
                                        onClose={() => setRecordingVoice(false)}
                                        onReturnFocus={() =>
                                            voiceTrigger.current?.focus()
                                        }
                                        onAdd={(file) => {
                                            setRecordingVoice(false);
                                            if (
                                                selection.current.files
                                                    .length >= policy.max_files
                                            ) {
                                                toast.error(
                                                    'Remove an attachment before adding a voice message.',
                                                );
                                                return;
                                            }
                                            setFiles(
                                                [
                                                    ...selection.current.files,
                                                    file,
                                                ],
                                                [
                                                    ...selection.current
                                                        .captions,
                                                    '',
                                                ],
                                            );
                                            setAttachmentsOpen(true);
                                        }}
                                    />
                                )}
                            <ChatAttachmentQueue
                                key={key}
                                open={attachmentsOpen}
                                onOpenChange={setAttachmentsOpen}
                                files={files}
                                uploads={uploads.rows}
                                onPrepare={uploads.prepare}
                                captions={fileCaptions}
                                maxFiles={policy.max_files}
                                maxMb={policy.max_file_mb}
                                locked={
                                    sending ||
                                    recoveryLocked ||
                                    !!pendingBody.current
                                }
                                available={
                                    conversation.can_send &&
                                    policy.enabled &&
                                    policy.files_enabled
                                }
                                sending={
                                    sending &&
                                    !checkingSend &&
                                    !!pendingBody.current?.has('files[]')
                                }
                                progress={uploadProgress}
                                onChoose={() => fileInput.current?.click()}
                                onRemove={(index) => {
                                    void removeFile(files[index]);
                                }}
                                onCaption={(index, caption) => {
                                    const next = files.map((_, i) =>
                                        i === index
                                            ? caption
                                            : (fileCaptions[i] ?? ''),
                                    );
                                    setFileCaptions(next);
                                    selection.current = {
                                        files,
                                        captions: next,
                                    };
                                    saveChatThreadMemory(key, {
                                        fileCaptions: next,
                                    });
                                }}
                                onClear={async () => {
                                    let removed = true;
                                    for (const file of files) {
                                        if (keyRef.current !== key)
                                            return false;
                                        if (!(await removeFile(file)))
                                            removed = false;
                                    }
                                    return removed;
                                }}
                                onReturnFocus={() =>
                                    attachmentTrigger.current?.focus()
                                }
                            />
                            {checkingSend && (
                                <p
                                    role="status"
                                    className="mb-2 text-sm text-muted-foreground"
                                >
                                    Checking whether your message was sent…
                                </p>
                            )}
                            {sendError && (
                                <div
                                    role="alert"
                                    className={cn(
                                        'mb-2 rounded-lg p-3 text-sm',
                                        pendingBody.current
                                            ? 'bg-status-warning-bg text-status-warning'
                                            : 'bg-status-critical-bg text-status-critical',
                                    )}
                                >
                                    <p>{sendError}</p>
                                    <p>
                                        {pendingBody.current
                                            ? !conversation.can_send ||
                                              !policy.enabled
                                                ? 'Check again to confirm the result. This will not resend your message.'
                                                : 'Retry checks the result first and keeps the same reference to avoid duplicates.'
                                            : 'The message was not accepted. Your draft and files are kept so you can correct them and try again.'}
                                    </p>
                                </div>
                            )}
                            {pendingBody.current &&
                                (!conversation.can_send || !policy.enabled) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="mb-2 h-auto min-h-[44px] whitespace-normal"
                                        disabled={sending}
                                        onClick={() => void send(true)}
                                    >
                                        {sending
                                            ? 'Checking previous send…'
                                            : 'Check previous send'}
                                    </Button>
                                )}
                            {conversation.can_send && policy.enabled ? (
                                <form
                                    ref={composerForm}
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void send();
                                    }}
                                    className="flex flex-col gap-2 rounded-xl border bg-background p-2 focus-within:ring-2 focus-within:ring-primary/30"
                                >
                                    <ChatMentionInput
                                        placeholder={
                                            conversation.type === 'family'
                                                ? 'Message the family and care team…'
                                                : undefined
                                        }
                                        enterSends={
                                            personal.sending === 'enter_sends'
                                        }
                                        textSize={
                                            personal.text_size === 'normal'
                                                ? undefined
                                                : chatReadingClass(
                                                      personal.text_size,
                                                  )
                                        }
                                        value={draft}
                                        mentions={mentions}
                                        people={conversation.participants.filter(
                                            (person) =>
                                                person.id !== actorId &&
                                                conversation.mentionable_ids?.includes(
                                                    person.id,
                                                ),
                                        )}
                                        disabled={
                                            sending ||
                                            recoveryLocked ||
                                            !!pendingBody.current
                                        }
                                        onChange={(value, selectedMentions) => {
                                            setDraft(value);
                                            setMentions(selectedMentions);
                                            saveChatDraft(key, value);
                                            saveChatThreadMemory(key, {
                                                mentions: selectedMentions,
                                            });
                                        }}
                                        onSend={() => void send()}
                                    />
                                    <div className="flex items-center gap-1">
                                        {policy.files_enabled && (
                                            <>
                                                <input
                                                    ref={fileInput}
                                                    type="file"
                                                    aria-label="Choose chat files"
                                                    multiple
                                                    className="hidden"
                                                    accept={CHAT_FILE_ACCEPT}
                                                    disabled={
                                                        sending ||
                                                        recoveryLocked ||
                                                        !!pendingBody.current
                                                    }
                                                    onChange={(event) => {
                                                        const chosen =
                                                            Array.from(
                                                                event.target
                                                                    .files ??
                                                                    [],
                                                            );
                                                        event.target.value = '';
                                                        if (
                                                            sending ||
                                                            recoveryLocked ||
                                                            pendingBody.current ||
                                                            !chosen.length
                                                        )
                                                            return;
                                                        if (
                                                            chosen.length +
                                                                files.length >
                                                            policy.max_files
                                                        ) {
                                                            toast.error(
                                                                `Choose up to ${policy.max_files} files, each no larger than ${policy.max_file_mb} MB.`,
                                                            );
                                                            return;
                                                        }
                                                        setFiles([
                                                            ...files,
                                                            ...chosen,
                                                        ]);
                                                        setAttachmentsOpen(
                                                            true,
                                                        );
                                                    }}
                                                />
                                                <Button
                                                    ref={attachmentTrigger}
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-[44px] shrink-0"
                                                    aria-label="Attach files"
                                                    disabled={
                                                        sending ||
                                                        recoveryLocked ||
                                                        !!pendingBody.current
                                                    }
                                                    onClick={() =>
                                                        setAttachmentsOpen(true)
                                                    }
                                                >
                                                    <Paperclip className="size-5" />
                                                </Button>
                                            </>
                                        )}

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-[44px] shrink-0"
                                            aria-label="Insert emoji"
                                            disabled={
                                                sending ||
                                                recoveryLocked ||
                                                !!pendingBody.current ||
                                                draft.length > 9997
                                            }
                                            onClick={() => {
                                                const next = draft + ' 🙂';
                                                setDraft(next);
                                                saveChatDraft(key, next);
                                            }}
                                        >
                                            <Smile className="size-5" />
                                        </Button>
                                        {policy.voice_notes_enabled &&
                                            policy.voice_notes_available &&
                                            policy.files_enabled && (
                                                <Button
                                                    ref={voiceTrigger}
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-[44px] shrink-0"
                                                    aria-label="Record a voice message"
                                                    disabled={
                                                        sending ||
                                                        recoveryLocked ||
                                                        !!pendingBody.current ||
                                                        files.length >=
                                                            policy.max_files
                                                    }
                                                    onClick={() =>
                                                        setRecordingVoice(true)
                                                    }
                                                >
                                                    <Mic className="size-5" />
                                                </Button>
                                            )}
                                        <Button
                                            type="submit"
                                            className="ml-auto min-h-[44px] shrink-0 px-4"
                                            disabled={
                                                sending ||
                                                recoveryLocked ||
                                                (recoveryEnabled &&
                                                    !pendingBody.current &&
                                                    recovery?.draft.phase !==
                                                        'ready') ||
                                                (!pendingBody.current &&
                                                    (!filesReady ||
                                                        files.length >
                                                            policy.max_files ||
                                                        files.some(
                                                            (file) =>
                                                                !!chatFileIssue(
                                                                    file,
                                                                    policy.max_file_mb,
                                                                ),
                                                        ))) ||
                                                (!!unresolvedReply &&
                                                    !pendingBody.current) ||
                                                (!draft.trim() &&
                                                    !files.length &&
                                                    !pendingBody.current)
                                            }
                                            aria-label={
                                                checkingSend
                                                    ? 'Checking previous send'
                                                    : sendError
                                                      ? 'Retry sending message'
                                                      : 'Send message'
                                            }
                                        >
                                            {sending ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                <Send className="size-4" />
                                            )}
                                            {checkingSend
                                                ? 'Checking…'
                                                : sending
                                                  ? 'Sending…'
                                                  : sendError
                                                    ? 'Retry'
                                                    : 'Send'}
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <p className="p-2 text-sm text-muted-foreground">
                                    {conversation.archived ||
                                    conversation.archived_for_me
                                        ? conversation.archived_for_me
                                            ? 'Archived for you. Open Conversation details to unarchive before sending.'
                                            : 'This conversation is closed.'
                                        : 'You cannot send messages in this conversation.'}
                                </p>
                            )}
                            {conversation.can_send && policy.enabled && (
                                <p className="mt-2 text-[11px] text-muted-foreground">
                                    {conversation.type === 'family'
                                        ? 'Family and authorised cover can read this.'
                                        : 'Conversation members only.'}{' '}
                                    {chatSendingHint(personal.sending)}
                                    {(draft || files.length > 0) &&
                                        (recovery?.draft.phase === 'saving'
                                            ? ' · Saving draft…'
                                            : recovery?.draft.phase ===
                                                    'ready' &&
                                                !recovery.draft.dirty &&
                                                !recoveryLocked
                                              ? files.some(
                                                    (file) =>
                                                        !isServerChatFile(
                                                            file,
                                                        ) &&
                                                        !uploads.rows.some(
                                                            (row) =>
                                                                row.file ===
                                                                    file &&
                                                                [
                                                                    'ready',
                                                                    'scanning',
                                                                ].includes(
                                                                    row.state,
                                                                ),
                                                        ),
                                                )
                                                  ? ' · Text saved privately · Check files before closing'
                                                  : ' · Draft saved privately'
                                              : ' · Draft kept in this tab')}
                                </p>
                            )}
                        </>
                    )}
                </footer>
                <Dialog
                    open={Boolean(actionMessage)}
                    onOpenChange={(open) => {
                        if (!open && !acting) setActionMessage(null);
                    }}
                >
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                        className="max-h-[85dvh] overflow-x-hidden overflow-y-auto"
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            if (!deleting && !editing)
                                actionTrigger.current?.focus();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Message actions</DialogTitle>
                            <DialogDescription>
                                {actionMessage?.sender_name} ·{' '}
                                {actionMessage &&
                                    formatDateTime(actionMessage.created_at)}
                            </DialogDescription>
                        </DialogHeader>
                        <p className="line-clamp-4 rounded-xl bg-muted/40 p-3 text-sm break-words">
                            {actionMessage?.content || 'Attachment'}
                        </p>
                        {actionMessage && (
                            <div className="grid grid-cols-1 gap-2 min-[360px]:grid-cols-2">
                                <Button
                                    variant="outline"
                                    className="min-h-[44px] justify-start"
                                    disabled={acting || !conversation.can_send}
                                    onClick={() => {
                                        setSearchOpen(false);
                                        setReply(actionMessage);
                                        setActionMessage(null);
                                        requestAnimationFrame(() =>
                                            composerForm.current
                                                ?.querySelector('textarea')
                                                ?.focus(),
                                        );
                                    }}
                                >
                                    <Reply className="size-4" />
                                    Reply
                                </Button>
                                <Button
                                    variant="outline"
                                    className="min-h-[44px] justify-start"
                                    disabled={acting}
                                    onClick={() =>
                                        void action(actionMessage, 'save')
                                    }
                                >
                                    <Bookmark className="size-4" />
                                    {actionMessage.saved
                                        ? 'Unsave'
                                        : 'Save for me'}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="min-h-[44px] justify-start"
                                    disabled={acting || !conversation.can_send}
                                    onClick={() =>
                                        void action(
                                            actionMessage,
                                            'react',
                                            '👍',
                                        )
                                    }
                                >
                                    👍{' '}
                                    {actionMessage.reactions.some(
                                        (r) => r.emoji === '👍' && r.mine,
                                    )
                                        ? 'Remove reaction'
                                        : 'Acknowledge'}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="min-h-[44px] justify-start"
                                    disabled={acting || !conversation.can_send}
                                    onClick={() =>
                                        void action(actionMessage, 'pin')
                                    }
                                >
                                    <Pin className="size-4" />
                                    {actionMessage.pinned ? 'Unpin' : 'Pin'}
                                </Button>
                                {actionMessage.mine && (
                                    <Button
                                        variant="outline"
                                        className="min-h-[44px] justify-start"
                                        disabled={
                                            acting ||
                                            sending ||
                                            !!pendingBody.current ||
                                            !!editing ||
                                            !actionMessage.can_edit ||
                                            !conversation.can_send
                                        }
                                        onClick={() => {
                                            const next = {
                                                messageId: actionMessage.id,
                                                version:
                                                    actionMessage.edit_version ??
                                                    0,
                                                content: actionMessage.content,
                                                mentions:
                                                    actionMessage.mentions ??
                                                    [],
                                            };
                                            saveChatThreadMemory(key, {
                                                editing: next,
                                            });
                                            setSearchOpen(false);
                                            setEditing(next);
                                            setActionMessage(null);
                                        }}
                                    >
                                        <Pencil className="size-4" />
                                        Edit my message
                                    </Button>
                                )}
                                {actionMessage.mine && (
                                    <Button
                                        variant="outline"
                                        className="min-h-[44px] justify-start"
                                        disabled={
                                            acting ||
                                            !!editing ||
                                            sending ||
                                            !!pendingBody.current ||
                                            !conversation.can_send ||
                                            !actionMessage.can_remove
                                        }
                                        onClick={() => {
                                            const next = {
                                                messageId: actionMessage.id,
                                                version:
                                                    actionMessage.edit_version ??
                                                    0,
                                                content: actionMessage.content,
                                            };
                                            saveChatThreadMemory(key, {
                                                removing: next,
                                            });
                                            setDeleting(next);
                                            setActionMessage(null);
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                        Remove my message
                                    </Button>
                                )}
                            </div>
                        )}
                        {acting && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Saving your change…
                            </p>
                        )}
                        {!actionMessage?.mine && (
                            <p className="text-xs text-muted-foreground">
                                Only the sender can edit or remove this message.
                            </p>
                        )}
                        {actionMessage?.mine &&
                            actionMessage.change_unavailable_reason && (
                                <p className="text-xs text-muted-foreground">
                                    {actionMessage.change_unavailable_reason}
                                </p>
                            )}
                    </DialogContent>
                </Dialog>
                {deleting && (
                    <ChatMessageRemoval
                        actorId={actorId}
                        key={`${key}:${deleting.messageId}`}
                        memoryKey={key}
                        conversationId={conversationId}
                        initial={deleting}
                        canSend={conversation.can_send && policy.enabled}
                        onClose={() => setDeleting(null)}
                        onRemoved={() => {
                            // The action button disappears with the removed message.
                            actionTrigger.current = null;
                            setDeleting(null);
                            void load(undefined, true);
                            onChange?.();
                        }}
                        onReturnFocus={() => {
                            if (actionTrigger.current?.isConnected)
                                actionTrigger.current.focus();
                            else
                                composerForm.current
                                    ?.querySelector('textarea')
                                    ?.focus();
                        }}
                    />
                )}
            </section>
            {audienceReview && (
                <ChatAudienceReview
                    actorId={actorId}
                    key={`${actorId}:${audienceReview.id}`}
                    conversation={audienceReview}
                    onClose={() => setAudienceReview(null)}
                    onReviewed={(updated) => {
                        setHistory((current) =>
                            current
                                ? { ...current, conversation: updated }
                                : current,
                        );
                        setSendError('');
                        toast.success(
                            'Audience confirmed. Your message is ready to send.',
                        );
                    }}
                    onReturnFocus={() =>
                        composerForm.current?.querySelector('textarea')?.focus()
                    }
                />
            )}
            {details && (
                <ChatDetails
                    compact={compact}
                    conversation={conversation}
                    actorId={actorId}
                    onClose={() => {
                        setDetails(false);
                        requestAnimationFrame(() =>
                            detailsTrigger.current?.focus(),
                        );
                    }}
                    onMessage={(id) => {
                        setDetails(false);
                        void jumpToMessage(id);
                    }}
                    onChanged={() => {
                        void load(undefined, true);
                        onChange?.();
                    }}
                    onExit={() => {
                        setDetails(false);
                        onBack?.();
                    }}
                />
            )}
        </div>
    );
}
