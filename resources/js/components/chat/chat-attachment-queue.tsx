import { FilePreviewDialog } from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileText, Paperclip, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChatUploadDraft, ChatUploadProgress } from './api';
import { isServerChatFile, type ChatSelectedFile } from './chat-selected-file';
import { LocalVoicePreview } from './chat-voice-recorder';
import { isChatVoiceNote, voiceNoteDuration } from './chat-voice-recording';

const uploadLabels: Record<ChatUploadDraft['state'], string> = {
    selected: 'Selected locally',
    checking: 'Checking upload status…',
    uploading: 'Uploading…',
    scanning: 'Checking file safety…',
    ready: 'Ready to send',
    blocked: 'File blocked',
    unavailable: 'Safety check unavailable',
    changed: 'File changed',
    unknown: 'Upload not confirmed',
    removing: 'Removing…',
    remove_failed: 'Removal not confirmed',
    discarded: 'Upload removed',
    consumed: 'Already sent',
};
const preparationStates = new Set([
    'selected',
    'unknown',
    'unavailable',
    'scanning',
]);

export const CHAT_FILE_ACCEPT =
    '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.rtf';
const allowedTypes = new Set([
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv',
    'text/plain',
    'application/rtf',
    'text/rtf',
]);
export function chatFileIssue(
    file: ChatSelectedFile,
    maxMb: number,
): string | null {
    if (isServerChatFile(file) && file.unavailable)
        return 'This attachment is no longer available. Remove it and choose the file again.';
    if (file.size === 0)
        return 'This file is empty. Choose a file with content.';
    if (file.size > maxMb * 1024 * 1024)
        return `Too large. Choose a file no larger than ${maxMb} MB.`;
    if (
        isChatVoiceNote(file) &&
        [
            'audio/webm',
            'audio/ogg',
            'audio/mp4',
            'video/webm',
            'video/mp4',
            'application/ogg',
            'audio/x-m4a',
        ].includes(file.type)
    )
        return null;
    const extension = file.name.slice(file.name.lastIndexOf('.')).toLowerCase();
    if (
        !CHAT_FILE_ACCEPT.split(',').includes(extension) ||
        (file.type &&
            file.type !== 'application/octet-stream' &&
            !allowedTypes.has(file.type))
    )
        return 'Unsupported format. Choose a photo, PDF, Word, Excel or text document.';
    return null;
}

function FileThumbnail({ file }: { file: ChatSelectedFile }) {
    const [url, setUrl] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        setFailed(false);
        if (isServerChatFile(file)) {
            setUrl(null);
            return;
        }
        if (
            !['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(
                file.type,
            ) ||
            file.size > 20 * 1024 * 1024
        ) {
            setUrl(null);
            return;
        }
        const next = URL.createObjectURL(file);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [file]);
    return url && !failed ? (
        <img
            src={url}
            alt={`Selected photo: ${file.name}`}
            className="max-h-32 w-full rounded-md object-contain"
            onError={() => setFailed(true)}
        />
    ) : (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <FileText className="size-5" />
            {failed
                ? 'Photo preview unavailable; the original file is still selected.'
                : isServerChatFile(file)
                  ? 'Private upload restored'
                  : 'Document selected'}
        </span>
    );
}

export function ChatUploadStatus({
    progress,
}: {
    progress: ChatUploadProgress | null;
}) {
    const percent = progress?.total
        ? Math.min(100, Math.floor((progress.loaded / progress.total) * 100))
        : null;
    const confirming = percent === 100;
    return (
        <div
            role="status"
            className="mb-2 space-y-1 rounded-lg border bg-muted/40 p-3 text-sm"
        >
            <p>
                {confirming
                    ? 'Upload complete. Confirming the message…'
                    : percent === null
                      ? 'Uploading attachments…'
                      : `Uploading attachments… ${percent}%`}
            </p>
            {!confirming && (
                <progress
                    aria-label="File upload"
                    className="h-2 w-full accent-primary"
                    max={100}
                    value={percent ?? undefined}
                />
            )}
            <p className="text-xs text-muted-foreground">
                Files are not sent until the message is confirmed.
            </p>
        </div>
    );
}

export function ChatAttachmentQueue({
    open,
    onOpenChange,
    files,
    uploads,
    onPrepare,
    captions,
    maxFiles,
    maxMb,
    locked,
    available = true,
    sending,
    progress,
    onChoose,
    onRemove,
    onCaption,
    onClear,
    onReturnFocus,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    files: ChatSelectedFile[];
    uploads?: ChatUploadDraft[];
    onPrepare?: (file: ChatSelectedFile) => Promise<void>;
    captions: string[];
    maxFiles: number;
    maxMb: number;
    locked: boolean;
    available?: boolean;
    sending: boolean;
    progress: ChatUploadProgress | null;
    onChoose: () => void;
    onRemove: (index: number) => void;
    onCaption: (index: number, caption: string) => void;
    onClear: () => void | Promise<boolean>;
    onReturnFocus: () => void;
}) {
    const invalid =
        files.length > maxFiles ||
        files.some((file) => !!chatFileIssue(file, maxMb));
    const [clearing, setClearing] = useState(false);
    const [preparing, setPreparing] = useState(false);
    const ready =
        !onPrepare ||
        files.every((file) =>
            uploads?.some((row) => row.file === file && row.state === 'ready'),
        );
    const canPrepare = files.some((file) => {
        const row = uploads?.find((item) => item.file === file);
        return (
            row &&
            preparationStates.has(row.state) &&
            !chatFileIssue(file, maxMb)
        );
    });
    const labelFor = (file: ChatSelectedFile) => {
        if (isServerChatFile(file) && file.unavailable)
            return file.state === 'consumed'
                ? 'Already sent'
                : 'Attachment unavailable';
        const row = uploads?.find((item) => item.file === file);
        if (row?.state === 'uploading' && row.progress?.total)
            return `Uploading… ${Math.min(100, Math.floor((row.progress.loaded / row.progress.total) * 100))}%`;
        return row ? uploadLabels[row.state] : 'Selected locally';
    };
    const summaryTrigger = useRef<HTMLButtonElement>(null);
    const returnToSummary = useRef(false);
    const [preview, setPreview] = useState<ChatSelectedFile | null>(null);
    const previewTriggers = useRef(
        new Map<ChatSelectedFile, HTMLButtonElement>(),
    );
    const restorePreviewFocus = useRef<ChatSelectedFile | null>(null);
    const activePreview =
        open && preview && files.includes(preview) ? preview : null;
    useEffect(() => {
        if (!open || (preview && !files.includes(preview))) setPreview(null);
    }, [open, files, preview]);
    return (
        <>
            {files.length > 0 && (
                <div className="mb-2 space-y-1">
                    <Button
                        ref={summaryTrigger}
                        type="button"
                        variant="outline"
                        className="h-auto min-h-[44px] whitespace-normal"
                        onClick={() => {
                            returnToSummary.current = true;
                            onOpenChange(true);
                        }}
                    >
                        <Paperclip className="size-4" />
                        Review attachments ({files.length})
                    </Button>
                    <ul
                        aria-label="Selected attachments"
                        className="max-h-[132px] space-y-1 overflow-y-auto"
                    >
                        {files.map((file, index) => (
                            <li
                                key={`${file.name}-${index}`}
                                className="flex min-w-0 items-center gap-2 text-xs"
                            >
                                <span className="min-w-0 flex-1 [overflow-wrap:anywhere]">
                                    <span>{file.name}</span> ·{' '}
                                    {chatFileIssue(file, maxMb)
                                        ? 'Needs attention'
                                        : locked
                                          ? 'Awaiting confirmation'
                                          : labelFor(file)}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="size-[44px] shrink-0"
                                    aria-label={`Remove ${file.name}`}
                                    disabled={
                                        locked ||
                                        clearing ||
                                        uploads?.some(
                                            (row) =>
                                                row.file === file &&
                                                row.state === 'removing',
                                        )
                                    }
                                    onClick={() => onRemove(index)}
                                >
                                    <X className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                    {invalid && (
                        <p role="alert" className="text-sm text-destructive">
                            Review the attachments and remove files outside the
                            current limits before sending.
                        </p>
                    )}
                    {sending && !open && (
                        <ChatUploadStatus progress={progress} />
                    )}
                </div>
            )}
            <Dialog open={open && !activePreview} onOpenChange={onOpenChange}>
                <DialogContent
                    className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0 [&_button]:min-h-[44px] [&_button]:min-w-[44px] [&>button[data-slot=dialog-close]]:flex [&>button[data-slot=dialog-close]]:items-center [&>button[data-slot=dialog-close]]:justify-center"
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        if (activePreview) return;
                        if (
                            summaryTrigger.current &&
                            (returnToSummary.current || locked || !available)
                        )
                            summaryTrigger.current.focus();
                        else onReturnFocus();
                        returnToSummary.current = false;
                    }}
                    onOpenAutoFocus={(event) => {
                        if (restorePreviewFocus.current) {
                            event.preventDefault();
                            previewTriggers.current
                                .get(restorePreviewFocus.current)
                                ?.focus();
                            restorePreviewFocus.current = null;
                        }
                    }}
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-[64px]">
                        <DialogTitle>
                            {files.some(
                                (file) => voiceNoteDuration(file) !== null,
                            )
                                ? 'Review attachments'
                                : 'Add photos or documents'}
                        </DialogTitle>
                        <DialogDescription>
                            Up to {maxFiles} files, {maxMb} MB each. Nothing is
                            added to client records automatically.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                        <p className="text-xs text-muted-foreground">
                            JPG, PNG, GIF, WebP, PDF, Word, Excel and text
                            files, plus voice messages recorded in Chat. Upload
                            and check stores files privately for a safety check.
                            Files are shared with this conversation only when
                            you send the message. Photos keep any location or
                            camera details they contain.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full whitespace-normal"
                            onClick={onChoose}
                            disabled={
                                locked ||
                                clearing ||
                                !available ||
                                files.length >= maxFiles
                            }
                        >
                            <Paperclip className="size-4" />
                            Choose photos or documents
                        </Button>
                        {!files.length && (
                            <p className="text-sm text-muted-foreground">
                                Choose files to preview and add captions.
                            </p>
                        )}
                        {!available && (
                            <p role="status" className="text-sm">
                                File sharing is currently unavailable in this
                                conversation. Your selection is kept.
                            </p>
                        )}
                        {files.length > maxFiles && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                The current limit is {maxFiles} files. Remove
                                extra files before sending.
                            </p>
                        )}
                        {files.map((file, index) => {
                            const issue = chatFileIssue(file, maxMb);
                            const upload = uploads?.find(
                                (row) => row.file === file,
                            );
                            return (
                                <div
                                    key={`${file.name}-${index}`}
                                    className="space-y-2 rounded-lg border p-3"
                                >
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium [overflow-wrap:anywhere]">
                                                {file.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {isServerChatFile(file) &&
                                                file.unavailable
                                                    ? 'Size unavailable'
                                                    : `${Math.ceil(file.size / 1024)} KB`}
                                                {' · '}
                                                {locked
                                                    ? 'Awaiting confirmation'
                                                    : labelFor(file)}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="size-[44px] shrink-0"
                                            disabled={
                                                locked ||
                                                clearing ||
                                                upload?.state === 'removing'
                                            }
                                            aria-label={`Remove selected ${file.name}`}
                                            onClick={() => onRemove(index)}
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                    {upload && !locked && (
                                        <div
                                            role="status"
                                            className="space-y-1 text-sm"
                                        >
                                            {upload.state === 'uploading' && (
                                                <progress
                                                    aria-label={`Upload ${file.name}`}
                                                    className="h-2 w-full accent-primary"
                                                    max={
                                                        upload.progress
                                                            ?.total ?? 100
                                                    }
                                                    value={
                                                        upload.progress?.total
                                                            ? upload.progress
                                                                  .loaded
                                                            : undefined
                                                    }
                                                />
                                            )}
                                            {upload.state === 'ready' && (
                                                <p>
                                                    Safety check passed. Not
                                                    sent yet.
                                                </p>
                                            )}
                                            {upload.error && (
                                                <p className="[overflow-wrap:anywhere] text-destructive">
                                                    {upload.error}
                                                </p>
                                            )}
                                            {upload.state ===
                                                'remove_failed' && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={clearing}
                                                    onClick={() =>
                                                        onRemove(index)
                                                    }
                                                    aria-label={`Retry removing ${file.name}`}
                                                >
                                                    Retry removal
                                                </Button>
                                            )}
                                            {onPrepare &&
                                                !issue &&
                                                [
                                                    'unknown',
                                                    'unavailable',
                                                    'scanning',
                                                ].includes(upload.state) && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        disabled={
                                                            !available ||
                                                            preparing ||
                                                            clearing
                                                        }
                                                        onClick={() => {
                                                            void onPrepare(
                                                                file,
                                                            );
                                                        }}
                                                        aria-label={`Check ${file.name}`}
                                                    >
                                                        {upload.state ===
                                                        'unavailable'
                                                            ? 'Retry safety check'
                                                            : 'Check status'}
                                                    </Button>
                                                )}
                                        </div>
                                    )}
                                    {issue ? (
                                        <p
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {issue}
                                        </p>
                                    ) : (
                                        <>
                                            {voiceNoteDuration(file) !== null &&
                                            !isServerChatFile(file) ? (
                                                <LocalVoicePreview
                                                    file={file}
                                                    duration={
                                                        voiceNoteDuration(
                                                            file,
                                                        )! / 1000
                                                    }
                                                />
                                            ) : (
                                                <FileThumbnail file={file} />
                                            )}
                                            {(voiceNoteDuration(file) ===
                                                null ||
                                                isServerChatFile(file)) && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="h-auto w-full whitespace-normal"
                                                    ref={(element) => {
                                                        if (element)
                                                            previewTriggers.current.set(
                                                                file,
                                                                element,
                                                            );
                                                        else
                                                            previewTriggers.current.delete(
                                                                file,
                                                            );
                                                    }}
                                                    onClick={() =>
                                                        setPreview(file)
                                                    }
                                                    aria-label={`Preview selected ${file.name}`}
                                                >
                                                    Preview file
                                                </Button>
                                            )}
                                            <label className="block space-y-1 text-sm [overflow-wrap:anywhere]">
                                                Caption for {file.name}
                                                <textarea
                                                    className="min-h-[44px] w-full resize-y rounded-md border bg-background p-2 focus-visible:outline-2 focus-visible:outline-primary"
                                                    rows={2}
                                                    maxLength={1000}
                                                    placeholder="Add a caption (optional)"
                                                    disabled={locked}
                                                    value={
                                                        captions[index] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        onCaption(
                                                            index,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </>
                                    )}
                                </div>
                            );
                        })}
                        {sending && <ChatUploadStatus progress={progress} />}
                        {locked && !sending && (
                            <p role="status" className="text-sm">
                                A send result is not confirmed. Return to the
                                message and retry the same send before changing
                                its files.
                            </p>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 border-t p-4 sm:justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={locked || clearing || !files.length}
                            onClick={async () => {
                                setClearing(true);
                                try {
                                    if ((await onClear()) !== false)
                                        onOpenChange(false);
                                } finally {
                                    setClearing(false);
                                }
                            }}
                        >
                            {clearing
                                ? 'Removing attachments…'
                                : 'Cancel attachments'}
                        </Button>
                        <Button
                            type="button"
                            className="h-auto whitespace-normal"
                            disabled={
                                !locked &&
                                (!files.length ||
                                    invalid ||
                                    !available ||
                                    preparing ||
                                    clearing ||
                                    (!ready && !canPrepare))
                            }
                            onClick={async () => {
                                if (locked || ready) {
                                    onOpenChange(false);
                                    return;
                                }
                                setPreparing(true);
                                try {
                                    for (const file of files) {
                                        const row = uploads?.find(
                                            (item) => item.file === file,
                                        );
                                        if (
                                            row &&
                                            preparationStates.has(row.state)
                                        )
                                            await onPrepare?.(file);
                                    }
                                } finally {
                                    setPreparing(false);
                                }
                            }}
                        >
                            {locked
                                ? 'Back to message'
                                : preparing
                                  ? 'Checking files…'
                                  : ready
                                    ? 'Add to message'
                                    : 'Upload and check'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <FilePreviewDialog
                compact
                localFile={
                    activePreview && !isServerChatFile(activePreview)
                        ? activePreview
                        : undefined
                }
                file={
                    activePreview
                        ? {
                              id: isServerChatFile(activePreview)
                                  ? activePreview.uploadId
                                  : 'local-selection',
                              name: activePreview.name,
                              mime: activePreview.type,
                              bytes: activePreview.size,
                              durationMs: voiceNoteDuration(activePreview),
                              source: isServerChatFile(activePreview)
                                  ? 'Private upload · Not sent'
                                  : 'Local preview · Not sent',
                              previewUrl: isServerChatFile(activePreview)
                                  ? activePreview.previewUrl
                                  : null,
                              downloadUrl: isServerChatFile(activePreview)
                                  ? activePreview.downloadUrl
                                  : null,
                          }
                        : null
                }
                onReturnFocus={() => {
                    /* The reopened queue restores its preview trigger. */
                }}
                onClose={() => {
                    restorePreviewFocus.current = activePreview;
                    setPreview(null);
                }}
            />
        </>
    );
}
