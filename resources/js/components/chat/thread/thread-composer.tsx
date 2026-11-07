import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Loader2, Mic, Paperclip, Reply, Send, Smile, X } from 'lucide-react';
import { toast } from 'sonner';
import {
    CHAT_FILE_ACCEPT,
    ChatAttachmentQueue,
    chatFileIssue,
} from '../chat-attachment-queue';
import { ChatComposerRecovery } from '../chat-composer-recovery';
import { ChatMentionInput } from '../chat-mention-input';
import { ChatMessageEditor } from '../chat-message-editor';
import {
    chatReadingClass,
    chatSendingHint,
    type ChatPreferences,
} from '../chat-personal-preferences';
import { isServerChatFile } from '../chat-selected-file';
import { ChatVoiceRecorder } from '../chat-voice-recorder';
import type { ChatConversation, ChatMessage, ChatPolicy } from '../types';
import { ThreadOutboxBar } from './thread-outbox';
import type { ThreadComposer as Composer } from './use-thread-composer';
import type { ThreadOutbox } from './use-thread-outbox';

/** The bottom of the conversation: editing a sent message, or writing and sending a new one. */
export function ThreadComposer({
    composer,
    outbox,
    conversation,
    policy,
    personal,
    actorId,
    conversationId,
    hidden,
    onEdited,
}: {
    composer: Composer;
    outbox: ThreadOutbox;
    conversation: ChatConversation;
    policy: ChatPolicy;
    personal: ChatPreferences;
    actorId: number;
    conversationId: number;
    hidden: boolean;
    onEdited: (message: ChatMessage) => void;
}) {
    const {
        key,
        draft,
        files,
        fileCaptions,
        uploads,
        pending,
        sending,
        checkingSend,
        sendError,
        recovery,
        recoveryLocked,
        recoveryEnabled,
        locked,
        online,
        mentions,
        write,
        composerForm,
        fileInput,
        attachmentTrigger,
        voiceTrigger,
        filesReady,
        unresolvedReply,
    } = composer;
    const canWrite = conversation.can_send && policy.enabled;
    const people = conversation.participants.filter(
        (person) =>
            person.id !== actorId &&
            conversation.mentionable_ids?.includes(person.id),
    );
    return (
        <footer
            hidden={hidden}
            className="max-h-[55%] shrink-0 overflow-y-auto overscroll-contain border-t bg-background p-3 sm:p-4"
        >
            {composer.editing ? (
                <ChatMessageEditor
                    actorId={actorId}
                    key={`${key}:${composer.editing.messageId}`}
                    memoryKey={key}
                    conversationId={conversationId}
                    initial={composer.editing}
                    people={people}
                    canSend={canWrite}
                    onClose={() => {
                        composer.setEditing(null);
                        composer.focusSoon();
                    }}
                    onSaved={(message) => {
                        composer.setEditing(null);
                        onEdited(message);
                        composer.focusSoon();
                    }}
                />
            ) : (
                <>
                    {composer.composerSession && recovery && !pending && (
                        <ChatComposerRecovery
                            session={composer.composerSession}
                            state={recovery}
                            onReturnFocus={composer.focus}
                        />
                    )}
                    {composer.restoreError && (
                        <div
                            role="alert"
                            className="mb-2 rounded-lg border p-3 text-sm"
                        >
                            <p>{composer.restoreError}</p>
                            <Button
                                type="button"
                                variant="outline"
                                className="mt-2"
                                onClick={composer.retryRestore}
                            >
                                Retry restoring draft
                            </Button>
                        </div>
                    )}
                    {canWrite && (
                        <ThreadOutboxBar outbox={outbox} online={online} />
                    )}
                    {composer.reply && (
                        <div className="mb-2 flex items-center gap-2 rounded-lg border bg-muted/40 p-2 text-xs">
                            <Reply
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <strong>
                                    Replying to {composer.reply.sender_name}
                                </strong>
                                <p className="truncate">
                                    {composer.reply.content}
                                </p>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 shrink-0"
                                aria-label="Cancel reply"
                                disabled={recoveryLocked}
                                onClick={() => composer.setReply(null)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    )}
                    {composer.unresolvedReply && !pending && (
                        <div className="mb-2 rounded-lg border p-3 text-sm">
                            <p role={composer.replyError ? 'alert' : 'status'}>
                                {composer.replyError ||
                                    'Checking your previous reply…'}
                            </p>
                            <div className="mt-2 flex gap-2">
                                {composer.replyError && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={composer.retryReply}
                                    >
                                        Retry reply
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={recoveryLocked}
                                    onClick={() => composer.setReply(null)}
                                >
                                    Remove reply
                                </Button>
                            </div>
                        </div>
                    )}
                    {composer.recordingVoice &&
                        conversation.can_send &&
                        policy.enabled &&
                        policy.files_enabled &&
                        policy.voice_notes_enabled &&
                        policy.voice_notes_available &&
                        !sending &&
                        !pending && (
                            <ChatVoiceRecorder
                                key={`voice:${key}`}
                                maxMb={policy.max_file_mb}
                                onClose={() =>
                                    composer.setRecordingVoice(false)
                                }
                                onReturnFocus={() =>
                                    composer.voiceTrigger.current?.focus()
                                }
                                onAdd={(file) => {
                                    composer.setRecordingVoice(false);
                                    const selected = composer.selection.current;
                                    if (
                                        selected.files.length >=
                                        policy.max_files
                                    ) {
                                        toast.error(
                                            'Remove an attachment before adding a voice message.',
                                        );
                                        return;
                                    }
                                    composer.setFiles(
                                        [...selected.files, file],
                                        [...selected.captions, ''],
                                    );
                                    composer.setAttachmentsOpen(true);
                                }}
                            />
                        )}
                    <ChatAttachmentQueue
                        key={key}
                        open={composer.attachmentsOpen}
                        onOpenChange={composer.setAttachmentsOpen}
                        files={files}
                        uploads={uploads.rows}
                        onPrepare={uploads.prepare}
                        captions={fileCaptions}
                        maxFiles={policy.max_files}
                        maxMb={policy.max_file_mb}
                        locked={locked}
                        available={
                            conversation.can_send &&
                            policy.enabled &&
                            policy.files_enabled
                        }
                        sending={
                            sending &&
                            !checkingSend &&
                            !!pending?.has('files[]')
                        }
                        progress={composer.uploadProgress}
                        onChoose={() => composer.fileInput.current?.click()}
                        onRemove={(index) => {
                            void composer.removeFile(files[index]);
                        }}
                        onCaption={composer.setCaption}
                        onClear={async () => {
                            let removed = true;
                            for (const file of files) {
                                if (composer.keyRef.current !== key)
                                    return false;
                                if (!(await composer.removeFile(file)))
                                    removed = false;
                            }
                            return removed;
                        }}
                        onReturnFocus={() =>
                            composer.attachmentTrigger.current?.focus()
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
                                pending
                                    ? 'bg-status-warning-bg text-status-warning'
                                    : 'bg-status-critical-bg text-status-critical',
                            )}
                        >
                            <p>{sendError}</p>
                            {!pending && (
                                <p>
                                    Your text and files are kept, so you can fix
                                    them and send again.
                                </p>
                            )}
                        </div>
                    )}
                    {pending && !canWrite && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mb-2 h-auto whitespace-normal"
                            disabled={sending}
                            onClick={() => void composer.send(true)}
                        >
                            {sending
                                ? 'Checking previous send…'
                                : 'Check previous send'}
                        </Button>
                    )}
                    {canWrite ? (
                        <form
                            ref={composerForm}
                            onSubmit={(event) => {
                                event.preventDefault();
                                void composer.send();
                            }}
                            className="flex flex-col gap-2 rounded-xl border bg-background p-2 focus-within:ring-2 focus-within:ring-primary/30"
                        >
                            <ChatMentionInput
                                placeholder={
                                    conversation.type === 'family'
                                        ? 'Message the family and care team…'
                                        : undefined
                                }
                                enterSends={personal.sending === 'enter_sends'}
                                textSize={
                                    personal.text_size === 'normal'
                                        ? undefined
                                        : chatReadingClass(personal.text_size)
                                }
                                value={draft}
                                mentions={mentions}
                                people={people}
                                disabled={locked}
                                onChange={write}
                                onSend={() => void composer.send()}
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
                                            disabled={locked}
                                            onChange={(event) => {
                                                const chosen = Array.from(
                                                    event.target.files ?? [],
                                                );
                                                event.target.value = '';
                                                if (locked || !chosen.length)
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
                                                composer.setFiles([
                                                    ...files,
                                                    ...chosen,
                                                ]);
                                                composer.setAttachmentsOpen(
                                                    true,
                                                );
                                            }}
                                        />
                                        <Button
                                            ref={attachmentTrigger}
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 shrink-0"
                                            aria-label="Attach files"
                                            disabled={locked}
                                            onClick={() =>
                                                composer.setAttachmentsOpen(
                                                    true,
                                                )
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
                                    className="size-8 shrink-0"
                                    aria-label="Insert emoji"
                                    disabled={locked || draft.length > 9997}
                                    onClick={() =>
                                        write(draft + ' 🙂', mentions)
                                    }
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
                                            className="size-8 shrink-0"
                                            aria-label="Record a voice message"
                                            disabled={
                                                locked ||
                                                files.length >= policy.max_files
                                            }
                                            onClick={() =>
                                                composer.setRecordingVoice(true)
                                            }
                                        >
                                            <Mic className="size-5" />
                                        </Button>
                                    )}
                                <Button
                                    type="submit"
                                    className="ml-auto shrink-0 px-4"
                                    disabled={
                                        sending ||
                                        recoveryLocked ||
                                        (recoveryEnabled &&
                                            online &&
                                            !pending &&
                                            recovery?.draft.phase !==
                                                'ready') ||
                                        (!pending &&
                                            online &&
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
                                        (!!unresolvedReply && !pending) ||
                                        (!draft.trim() &&
                                            !files.length &&
                                            !pending)
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
                    {canWrite && (
                        <p className="mt-2 text-[11px] text-muted-foreground">
                            {conversation.type === 'family'
                                ? 'Family and authorised cover can read this.'
                                : 'Conversation members only.'}{' '}
                            {chatSendingHint(personal.sending)}
                            {(draft || files.length > 0) &&
                                (recovery?.draft.phase === 'saving'
                                    ? ' · Saving draft…'
                                    : recovery?.draft.phase === 'ready' &&
                                        !recovery.draft.dirty &&
                                        !recoveryLocked
                                      ? files.some(
                                            (file) =>
                                                !isServerChatFile(file) &&
                                                !uploads.rows.some(
                                                    (row) =>
                                                        row.file === file &&
                                                        [
                                                            'ready',
                                                            'scanning',
                                                        ].includes(row.state),
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
    );
}
