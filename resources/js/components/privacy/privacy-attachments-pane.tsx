/* eslint-disable no-restricted-syntax -- Bespoke attachment list surfaces (locked
 * shells, file rows, icon-button actions) with semantic design tokens only. */
/**
 * Privacy command-centre — documents/evidence pane for a detail dialog.
 *
 * Reuses the shared premium AttachmentUploader (drag-drop, multi-file, per-file
 * note + sensitive toggle, sequential upload-as-progress) and renders the list
 * of existing documents with download + delete. Sensitive files the viewer
 * isn't cleared for arrive as a locked shell (need-to-know).
 */
import {
    AttachmentUploader,
    formatFileSize,
} from '@/components/ui/file-dropzone';
import { fmtDate } from '@/pages/privacy/privacy-shared';
import { router } from '@inertiajs/react';
import {
    Download,
    FileText,
    ImageIcon,
    Lock,
    ShieldAlert,
    Trash2,
} from 'lucide-react';

export type PrivacyAttachmentItem =
    | { id: number; locked: true; is_sensitive: true }
    | {
          id: number;
          locked: false;
          name: string;
          mime: string | null;
          is_image: boolean;
          size: number | null;
          notes: string | null;
          is_sensitive: boolean;
          uploaded_by: string | null;
          created_at: string | null;
          download_url: string;
      };

export type PrivacyAttachableType =
    | 'request'
    | 'breach'
    | 'hold'
    | 'dpia'
    | 'retention';

export function PrivacyAttachmentsPane({
    attachableType,
    attachableId,
    attachments,
    canManage,
}: {
    attachableType: PrivacyAttachableType;
    attachableId: number;
    attachments: PrivacyAttachmentItem[];
    canManage: boolean;
}) {
    const remove = (id: number) =>
        router.delete(`/privacy/attachments/${id}`, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <div className="flex flex-col gap-3">
            {attachments.length ? (
                <div className="flex flex-col gap-2">
                    {attachments.map((a) =>
                        a.locked ? (
                            <div
                                key={a.id}
                                className="flex items-center gap-3 rounded-xl border border-dashed border-border bg-muted/30 p-3 text-muted-foreground"
                            >
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted">
                                    <Lock className="h-5 w-5" />
                                </span>
                                <div className="text-[13px]">
                                    <div className="font-semibold text-foreground">
                                        Restricted document
                                    </div>
                                    <div className="text-xs">
                                        Sensitive — need-to-know (privacy write
                                        access required)
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div
                                key={a.id}
                                className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3 transition-colors hover:border-primary/40"
                            >
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                    {a.is_image ? (
                                        <ImageIcon className="h-5 w-5" />
                                    ) : (
                                        <FileText className="h-5 w-5" />
                                    )}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1.5">
                                        <span className="truncate text-[13px] font-semibold">
                                            {a.name}
                                        </span>
                                        {a.is_sensitive ? (
                                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-warning">
                                                <ShieldAlert className="h-2.5 w-2.5" />{' '}
                                                Sensitive
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="truncate text-[11px] text-muted-foreground">
                                        {[
                                            a.size
                                                ? formatFileSize(a.size)
                                                : null,
                                            a.uploaded_by,
                                            fmtDate(a.created_at),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                    {a.notes ? (
                                        <div className="mt-0.5 text-xs text-muted-foreground">
                                            {a.notes}
                                        </div>
                                    ) : null}
                                </div>
                                <a
                                    href={a.download_url}
                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-primary"
                                    aria-label={`Download ${a.name}`}
                                >
                                    <Download className="h-4 w-4" />
                                </a>
                                {canManage ? (
                                    <button
                                        type="button"
                                        onClick={() => remove(a.id)}
                                        aria-label={`Remove ${a.name}`}
                                        className="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-status-critical"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                ) : null}
                            </div>
                        ),
                    )}
                </div>
            ) : (
                <div className="rounded-xl border border-dashed border-border p-4 text-center text-[13px] text-muted-foreground">
                    No documents attached yet.
                </div>
            )}

            {canManage ? (
                <AttachmentUploader
                    endpoint={`/privacy/attachments?attachable_type=${attachableType}&attachable_id=${attachableId}`}
                    noteField="notes"
                    sensitive={{
                        field: 'is_sensitive',
                        label: 'Sensitive — restrict to staff with privacy write access',
                    }}
                    hint="PDF, Word, Excel, images — up to 10 MB each"
                />
            ) : null}
        </div>
    );
}
