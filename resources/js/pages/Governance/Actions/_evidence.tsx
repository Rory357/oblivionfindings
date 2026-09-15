import { ConfirmDialog } from '@/components/confirm-dialog';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import { Button } from '@/components/ui/button';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { formatDateLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Download, FileText, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EVIDENCE_EXTENSIONS, evidenceFileProblem } from './_helpers';

export interface ActionEvidenceFile {
    id: number;
    original_name: string;
    mime_type: string | null;
    size_bytes: number;
    uploaded_at: string | null;
    uploaded_by_name: string | null;
    download_url: string;
    can_remove?: boolean;
}

export interface EarlierEvidenceFile {
    index: number;
    original_name: string;
    download_url: string;
}

/** What counts as proof, said once in plain words. */
export const EVIDENCE_EXPLAINER =
    "Upload proof the action is done, such as the signed document, the email confirming it (saved as a PDF), or a photo or report.";

const ACCEPT = EVIDENCE_EXTENSIONS.map((ext) => `.${ext}`).join(',');

function uploadError(error: unknown): string {
    const response = (
        error as {
            response?: {
                data?: {
                    message?: string;
                    errors?: Record<string, string[] | string>;
                };
            };
        }
    )?.response?.data;
    const firstError = response?.errors
        ? Object.values(response.errors)[0]
        : undefined;
    const fromErrors = Array.isArray(firstError) ? firstError[0] : firstError;
    return (
        fromErrors ??
        response?.message ??
        "The file didn't upload. Check your connection and try again."
    );
}

/**
 * Evidence for one action: the files already added (by name, with an
 * authorised download link) and — while the action is still open — a drop
 * zone to add more. Storage paths never reach this component.
 */
export function ActionEvidencePanel({
    actionId,
    evidence,
    earlierEvidence = [],
    canUpload,
    required,
}: {
    actionId: number;
    evidence: ActionEvidenceFile[];
    earlierEvidence?: EarlierEvidenceFile[];
    canUpload: boolean;
    required: boolean;
}) {
    const [uploading, setUploading] = useState<File[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [removing, setRemoving] = useState<ActionEvidenceFile | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);

    const upload = async (files: File[]) => {
        setError(null);
        const problem = files.map(evidenceFileProblem).find(Boolean);
        if (problem) {
            setError(problem);
            return;
        }

        const data = new FormData();
        files.forEach((file) => data.append('files[]', file));
        setUploading(files);
        try {
            await axios.post(`/governance/actions/${actionId}/evidence`, data, {
                headers: { Accept: 'application/json' },
            });
            router.reload({ only: ['action'] });
        } catch (caught) {
            setError(uploadError(caught));
        } finally {
            setUploading([]);
        }
    };

    const remove = async (file: ActionEvidenceFile) => {
        setError(null);
        setBusyId(file.id);
        try {
            await axios.delete(
                `/governance/actions/${actionId}/evidence/${file.id}`,
                { headers: { Accept: 'application/json' } },
            );
            router.reload({ only: ['action'] });
        } catch (caught) {
            setError(uploadError(caught));
        } finally {
            setBusyId(null);
        }
    };

    const total = evidence.length + earlierEvidence.length;

    return (
        <div className="flex flex-col gap-3" data-dusk="action-evidence">
            <p className="text-subtle flex items-start gap-1.5">
                <span>{EVIDENCE_EXPLAINER}</span>
                <GovernanceTermHint term="evidence" />
            </p>

            {total === 0 && uploading.length === 0 ? (
                <p className="text-caption">
                    {required
                        ? 'No evidence added yet. This action needs at least one file before it can be marked as done.'
                        : 'No evidence added.'}
                </p>
            ) : null}

            {evidence.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-label="Evidence files">
                    {evidence.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2"
                        >
                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <a
                                    href={file.download_url}
                                    className="block truncate text-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {file.original_name}
                                </a>
                                <p className="text-caption truncate">
                                    {[
                                        formatFileSize(file.size_bytes),
                                        file.uploaded_by_name
                                            ? `Added by ${file.uploaded_by_name}`
                                            : null,
                                        file.uploaded_at
                                            ? formatDateLong(file.uploaded_at)
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            <Button
                                asChild
                                variant="ghost"
                                size="icon"
                                aria-label={`Download ${file.original_name}`}
                            >
                                <a href={file.download_url}>
                                    <Download className="size-4" />
                                </a>
                            </Button>
                            {canUpload && file.can_remove ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${file.original_name}`}
                                    disabled={busyId === file.id}
                                    onClick={() => setRemoving(file)}
                                >
                                    {busyId === file.id ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <Trash2 className="size-4" />
                                    )}
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}

            {earlierEvidence.length > 0 ? (
                <div className="flex flex-col gap-1.5">
                    <p className="text-caption">
                        Added before file uploads were available
                    </p>
                    <ul className="flex flex-col gap-1.5">
                        {earlierEvidence.map((file) => (
                            <li
                                key={file.index}
                                className="flex items-center gap-2 text-sm"
                            >
                                <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                <a
                                    href={file.download_url}
                                    className="truncate underline-offset-4 hover:underline"
                                >
                                    {file.original_name}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {uploading.length > 0 ? (
                <ul className="flex flex-col gap-2" aria-live="polite">
                    {uploading.map((file) => (
                        <li
                            key={`${file.name}-${file.size}`}
                            className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2"
                        >
                            <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate text-sm">
                                {file.name}
                            </span>
                            <span className="text-caption">
                                Uploading… {formatFileSize(file.size)}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}

            {canUpload ? (
                <FileDropzone
                    id={`action-${actionId}-evidence`}
                    onFiles={(files) => void upload(files)}
                    accept={ACCEPT}
                    disabled={uploading.length > 0}
                    title="Drag and drop evidence here"
                    hint="PDF, Word, Excel, PowerPoint, JPG, PNG, GIF, WebP, CSV or text — up to 20 MB each"
                />
            ) : null}

            {error ? (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            ) : null}

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) void remove(removing);
                }}
                title="Remove this file?"
                description={`"${removing?.original_name ?? 'This file'}" will be removed from the action's evidence. You can upload it again later.`}
                confirmText="Remove file"
            />
        </div>
    );
}
