import { useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Download,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    Loader2,
    Trash2,
    Upload,
    X,
    type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';

export interface GovernanceAttachment {
    id: string;
    original_name: string;
    mime_type: string | null;
    size_bytes: number | null;
    uploaded_at: string | null;
    uploaded_by_name: string | null;
    download_url: string | null;
}

export interface GovernanceAttachmentsUrls {
    /** POST endpoint for multipart uploads (`files[]`). */
    upload: string;
    /** Resolves to a DELETE endpoint for a specific attachment id. */
    delete: (attachmentId: string) => string;
}

export interface GovernanceAttachmentsPanelProps {
    /** Whether files can currently be uploaded/removed. Renders read-only when false. */
    canManage: boolean;
    attachments: GovernanceAttachment[];
    /**
     * If null, the panel renders a "Save the draft first" placeholder.
     * Use when opening from a create dialog where the parent record doesn't yet
     * have an id.
     */
    urls: GovernanceAttachmentsUrls | null;
    /** Inertia prop to reload after a successful upload/delete. Default 'report'. */
    reloadProp?: string;
    /** Helper line shown under the drop zone. */
    helperText?: string;
    /** Override the empty-state copy when there are no attachments yet. */
    emptyText?: { managed: string; readOnly: string };
    /** Override the placeholder when `urls` is null. */
    placeholderText?: string;
    /** Called after a successful upload or delete (before the Inertia reload). */
    onChanged?: () => void;
}

const ACCEPTED_EXTENSIONS = [
    '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
    '.jpg', '.jpeg', '.png', '.gif', '.webp', '.csv', '.txt', '.md',
];
const ACCEPT_ATTR = ACCEPTED_EXTENSIONS.join(',');
const MAX_FILE_BYTES = 20 * 1024 * 1024; // 20 MB matches the server validation.

function iconForMime(mime: string | null): LucideIcon {
    if (!mime) return File;
    if (mime.startsWith('image/')) return FileImage;
    if (mime.includes('pdf')) return FileText;
    if (mime.includes('sheet') || mime.includes('excel') || mime.includes('csv')) return FileSpreadsheet;
    if (mime.startsWith('text/') || mime.includes('word') || mime.includes('document')) return FileText;
    return File;
}

function formatBytes(bytes: number | null): string {
    if (bytes == null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function formatTimestamp(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/**
 * Reusable attachments panel used across governance modules
 * (CEO Reports, Board Packs, etc). Pass `urls` so the panel knows where to
 * POST uploads and DELETE removals; download URLs live on each attachment.
 */
export function GovernanceAttachmentsPanel({
    canManage,
    attachments,
    urls,
    reloadProp = 'report',
    helperText = 'PDF, Office, images, CSV / TXT — up to 20 MB each, 10 files at a time.',
    emptyText = {
        managed: 'No attachments yet. Drop files above to add one.',
        readOnly: 'No supplementary documents have been attached.',
    },
    placeholderText = 'Save the draft first, then come back here to attach files.',
    onChanged,
}: GovernanceAttachmentsPanelProps) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);
    const [dragOver, setDragOver] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const triggerPicker = () => inputRef.current?.click();

    const uploadFiles = async (files: FileList | File[]) => {
        if (!urls) return;
        setError(null);

        const list = Array.from(files);
        if (list.length === 0) return;

        const tooBig = list.find((f) => f.size > MAX_FILE_BYTES);
        if (tooBig) {
            setError(`"${tooBig.name}" is larger than 20 MB. Pick a smaller file.`);
            return;
        }

        const formData = new FormData();
        for (const file of list) {
            formData.append('files[]', file);
        }

        setUploading(true);
        try {
            await axios.post(urls.upload, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            onChanged?.();
            router.reload({ only: [reloadProp] });
        } catch (err: any) {
            const message =
                err?.response?.data?.message ??
                err?.response?.data?.errors?.['files.0']?.[0] ??
                'Upload failed. Check the file type and size, then try again.';
            setError(message);
        } finally {
            setUploading(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    };

    const deleteAttachment = async (attachment: GovernanceAttachment) => {
        if (!urls) return;
        setError(null);
        try {
            await axios.delete(urls.delete(attachment.id));
            onChanged?.();
            router.reload({ only: [reloadProp] });
        } catch (err: any) {
            setError(err?.response?.data?.message ?? 'Failed to remove attachment.');
        }
    };

    if (!urls) {
        return (
            <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                {placeholderText}
            </div>
        );
    }

    return (
        <div className="space-y-3" data-dusk="governance-attachments-panel">
            {canManage && (
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragOver(false);
                        if (uploading) return;
                        if (e.dataTransfer.files?.length) {
                            void uploadFiles(e.dataTransfer.files);
                        }
                    }}
                    className={cn(
                        'flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center transition',
                        dragOver
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-primary/40',
                    )}
                >
                    <Upload className="h-6 w-6 text-muted-foreground" aria-hidden="true" />
                    <p className="text-sm font-medium text-foreground">
                        Drag files here or click to browse
                    </p>
                    <p className="text-xs text-muted-foreground">{helperText}</p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={triggerPicker}
                        disabled={uploading}
                    >
                        {uploading ? (
                            <>
                                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                Uploading…
                            </>
                        ) : (
                            <>
                                <Upload className="mr-1.5 h-4 w-4" />
                                Choose files
                            </>
                        )}
                    </Button>
                    <input
                        ref={inputRef}
                        type="file"
                        multiple
                        className="hidden"
                        accept={ACCEPT_ATTR}
                        onChange={(e) => {
                            if (e.target.files) void uploadFiles(e.target.files);
                        }}
                    />
                </div>
            )}

            {error && (
                <div className="flex items-start justify-between gap-3 rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                    <span>{error}</span>
                    <button
                        type="button"
                        onClick={() => setError(null)}
                        aria-label="Dismiss error"
                        className="shrink-0"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}

            {attachments.length === 0 ? (
                <div className="rounded-lg border border-border bg-muted/30 p-6 text-center text-sm text-muted-foreground">
                    {canManage ? emptyText.managed : emptyText.readOnly}
                </div>
            ) : (
                <ul className="space-y-2" aria-label="Attachments">
                    {attachments.map((a) => {
                        const Icon = iconForMime(a.mime_type);
                        const meta = [
                            formatBytes(a.size_bytes),
                            a.uploaded_by_name,
                            formatTimestamp(a.uploaded_at),
                        ]
                            .filter(Boolean)
                            .join(' · ');
                        return (
                            <li
                                key={a.id}
                                className="flex items-center gap-3 rounded-lg border border-border bg-card p-3"
                                data-dusk={`governance-attachment-${a.id}`}
                            >
                                <div className="rounded-md bg-muted p-2">
                                    <Icon className="h-5 w-5 text-foreground" aria-hidden="true" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-foreground" title={a.original_name}>
                                        {a.original_name}
                                    </p>
                                    {meta && (
                                        <p className="truncate text-xs text-muted-foreground">{meta}</p>
                                    )}
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    {a.download_url && (
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Download ${a.original_name}`}
                                        >
                                            <a href={a.download_url} download>
                                                <Download className="h-4 w-4" />
                                            </a>
                                        </Button>
                                    )}
                                    {canManage && (
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remove ${a.original_name}`}
                                                    className="text-status-critical"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>Remove attachment?</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        <span className="font-medium">{a.original_name}</span>{' '}
                                                        will be permanently removed. This cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() => void deleteAttachment(a)}
                                                        className="bg-status-critical hover:bg-status-critical/90"
                                                    >
                                                        Remove
                                                    </AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

export default GovernanceAttachmentsPanel;
