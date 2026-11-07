import {
    useEffect,
    useState,
    type Dispatch,
    type RefObject,
    type SetStateAction,
} from 'react';
import {
    readChatDraft,
    readChatThreadMemory,
    readPendingChatSend,
    saveChatDraft,
    saveChatThreadMemory,
} from '../api';
import {
    chatComposerSession,
    type ChatComposerSession,
    type ComposerRecoveryState,
} from '../chat-composer-session';
import type { PrivateChatDraft } from '../chat-private-draft';
import {
    isServerChatFile,
    restoreChatFiles,
    type ChatSelectedFile,
} from '../chat-selected-file';
import type {
    ChatHistory,
    ChatMention,
    ChatMessage,
    ChatPolicy,
} from '../types';

/**
 * Protected server drafts (spec 6.1), when draft recovery is on: the composer's text, reply,
 * mentions and checked uploads are saved privately on the server, restored on another device, and
 * cleared if the audience or access changes. The composer owns the values; this keeps the session.
 */
export function useComposerRecovery({
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
    uploadRows,
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
}: {
    actorId: number;
    conversationId: number;
    draftKey: string;
    keyRef: { current: string };
    hasConversation: boolean;
    historyRef: RefObject<ChatHistory | null>;
    policy: ChatPolicy;
    /** An unconfirmed send from before draft recovery: it takes precedence. */
    pending: FormData | null;
    fileMemoryKey: string | null;
    value: {
        draft: string;
        mentions: ChatMention[];
        files: ChatSelectedFile[];
        fileCaptions: string[];
        reply: ChatMessage | null;
        unresolvedReply: number | null;
    };
    uploadRows: { file: ChatSelectedFile; id: string; state: string }[];
    set: {
        setDraft: Dispatch<SetStateAction<string>>;
        setMentions: Dispatch<SetStateAction<ChatMention[]>>;
        setFiles: Dispatch<SetStateAction<ChatSelectedFile[]>>;
        setFileCaptions: Dispatch<SetStateAction<string[]>>;
        setReply: Dispatch<SetStateAction<ChatMessage | null>>;
        setUnresolvedReply: Dispatch<SetStateAction<number | null>>;
        setReplyRetry: Dispatch<SetStateAction<number>>;
    };
    replyRecovery: RefObject<AbortController | null>;
    composerForm: RefObject<HTMLFormElement | null>;
}) {
    const [composerSession, setComposerSession] =
        useState<ChatComposerSession | null>(null);
    const [recovery, setRecovery] = useState<ComposerRecoveryState | null>(
        null,
    );
    const [restoreError, setRestoreError] = useState('');
    const [restoreRetry, setRestoreRetry] = useState(0);
    // Protected server drafts (spec 6.1), when draft recovery is on.
    const recoveryEnabled = policy.draft_recovery_enabled === true;
    const legacyPending = !!pending;
    const recoveryLocked =
        recoveryEnabled &&
        !pending &&
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
                      const row = uploadRows.find(
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
        historyRef,
    ]);
    useEffect(() => {
        if (
            !recoveryEnabled ||
            !composerSession ||
            recoveryLocked ||
            fileMemoryKey !== key ||
            pending
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
        pending,
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
        setDraft,
        setMentions,
        setFilesState,
        setFileCaptions,
        setReplyState,
        setUnresolvedReply,
        replyRecovery,
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
        setDraft,
        setMentions,
        setFilesState,
        setFileCaptions,
        setReplyState,
        setUnresolvedReply,
        setReplyRetry,
        composerForm,
        replacement,
        composerSession,
        fileMemoryKey,
        key,
        actorId,
        conversationId,
        restoreRetry,
        keyRef,
    ]);

    return {
        composerSession,
        recovery,
        recoveryEnabled,
        recoveryLocked,
        recoveryValue,
        restoreError,
        retryRestore: () => setRestoreRetry((value) => value + 1),
    };
}
