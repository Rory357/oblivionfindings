import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { Paperclip, Trash2, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export type SupportingEvidenceAttachment = {
    id: number;
    file_name: string;
    mime_type?: string | null;
    file_size?: number | null;
    formatted_size?: string | null;
    description?: string | null;
    uploaded_at?: string | null;
    uploaded_by?: string | null;
    download_url: string;
    can_delete: boolean;
};

type Props = {
    isOpen: boolean;
    onClose: () => void;
    clientId: number | null;
    targetType: 'error' | 'discrepancy' | 'loss_report' | 'correction';
    targetId: number | null;
    title: string;
    subject: string;
    attachments?: SupportingEvidenceAttachment[];
    canManage: boolean;
    onAttachmentsChange?: (attachments: SupportingEvidenceAttachment[]) => void;
};

export default function SupportingEvidenceDialog({
    isOpen,
    onClose,
    clientId,
    targetType,
    targetId,
    title,
    subject,
    attachments: initialAttachments = [],
    canManage,
    onAttachmentsChange,
}: Props) {
    const [attachments, setAttachments] = useState<
        SupportingEvidenceAttachment[]
    >([]);
    const [description, setDescription] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    useEffect(() => {
        setAttachments(initialAttachments);
        setDescription('');
        setFile(null);
    }, [initialAttachments, isOpen, targetId]);

    async function handleUpload() {
        if (!clientId || !targetId || !file) {
            return;
        }

        const formData = new FormData();
        formData.append('target_type', targetType);
        formData.append('target_id', String(targetId));
        formData.append('file', file);

        if (description.trim()) {
            formData.append('description', description.trim());
        }

        setUploading(true);

        try {
            const response = await axios.post(
                `/api/medications/clients/${clientId}/attachments`,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                },
            );

            const nextAttachments = [
                response.data.attachment as SupportingEvidenceAttachment,
                ...attachments,
            ];

            setAttachments(nextAttachments);
            onAttachmentsChange?.(nextAttachments);
            setDescription('');
            setFile(null);
            toast.success('Evidence attachment uploaded.');
        } catch (error: unknown) {
            toast.error(
                axios.isAxiosError(error)
                    ? error.response?.data?.error ||
                          error.response?.data?.message ||
                          'Failed to upload evidence attachment.'
                    : 'Failed to upload evidence attachment.',
            );
        } finally {
            setUploading(false);
        }
    }

    async function handleDelete(attachmentId: number) {
        if (!clientId) {
            return;
        }

        setDeletingId(attachmentId);

        try {
            await axios.delete(
                `/api/medications/clients/${clientId}/attachments/${attachmentId}`,
            );

            const nextAttachments = attachments.filter(
                (attachment) => attachment.id !== attachmentId,
            );
            setAttachments(nextAttachments);
            onAttachmentsChange?.(nextAttachments);
            toast.success('Evidence attachment removed.');
        } catch (error: unknown) {
            toast.error(
                axios.isAxiosError(error)
                    ? error.response?.data?.error ||
                          error.response?.data?.message ||
                          'Failed to remove evidence attachment.'
                    : 'Failed to remove evidence attachment.',
            );
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Paperclip className="h-5 w-5" />
                        {title}
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="rounded-md border bg-muted/20 p-3 text-sm">
                        <div className="font-medium">{subject}</div>
                        <div className="mt-1 text-muted-foreground">
                            Upload photos, scans, statements, or supporting
                            documents for this medication record.
                        </div>
                    </div>

                    {canManage && targetId ? (
                        <div className="space-y-3 rounded-md border p-4">
                            <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                                <div className="space-y-2">
                                    <Label htmlFor="supporting-evidence-file">
                                        Upload evidence
                                    </Label>
                                    <Input
                                        id="supporting-evidence-file"
                                        type="file"
                                        onChange={(event) =>
                                            setFile(
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </div>
                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        onClick={handleUpload}
                                        disabled={!file || uploading}
                                    >
                                        <Upload className="mr-2 h-4 w-4" />
                                        {uploading ? 'Uploading...' : 'Upload'}
                                    </Button>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="supporting-evidence-description">
                                    Description
                                </Label>
                                <Textarea
                                    id="supporting-evidence-description"
                                    value={description}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                    placeholder="Optional note about this supporting evidence..."
                                    className="min-h-[80px]"
                                />
                            </div>
                        </div>
                    ) : null}

                    <div className="space-y-3">
                        <div className="text-sm font-medium">
                            Uploaded Evidence
                        </div>
                        {attachments.length === 0 ? (
                            <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                                No supporting evidence has been attached yet.
                            </div>
                        ) : (
                            attachments.map((attachment) => (
                                <div
                                    key={attachment.id}
                                    className="flex flex-col gap-3 rounded-md border p-3 md:flex-row md:items-start md:justify-between"
                                >
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <a
                                                href={attachment.download_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="font-medium hover:underline"
                                            >
                                                {attachment.file_name}
                                            </a>
                                            {attachment.mime_type ? (
                                                <Badge variant="outline">
                                                    {attachment.mime_type}
                                                </Badge>
                                            ) : null}
                                            {attachment.formatted_size ? (
                                                <Badge variant="secondary">
                                                    {attachment.formatted_size}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        {attachment.description ? (
                                            <div className="text-sm text-muted-foreground">
                                                {attachment.description}
                                            </div>
                                        ) : null}
                                        <div className="text-xs text-muted-foreground">
                                            Uploaded by{' '}
                                            {attachment.uploaded_by ??
                                                'Unknown'}
                                            {attachment.uploaded_at
                                                ? ` on ${new Date(
                                                      attachment.uploaded_at,
                                                  ).toLocaleString('en-NZ', {
                                                      dateStyle: 'short',
                                                      timeStyle: 'short',
                                                  })}`
                                                : ''}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <a
                                            href={attachment.download_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Button variant="outline" size="sm">
                                                Download
                                            </Button>
                                        </a>
                                        {attachment.can_delete && canManage ? (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                onClick={() =>
                                                    handleDelete(attachment.id)
                                                }
                                                disabled={
                                                    deletingId === attachment.id
                                                }
                                            >
                                                <Trash2 className="h-4 w-4 text-red-600" />
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
