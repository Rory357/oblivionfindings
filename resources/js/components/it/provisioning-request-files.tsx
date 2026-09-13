import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import { Paperclip } from 'lucide-react';

/** Metadata is projected by the server under current canonical result access. */
export interface ProvisioningAttachment {
    id: number;
    name: string;
    size: number;
    url: string;
    catalogue_field_label: string;
    is_internal: boolean;
}

export function ProvisioningSubmittedFiles({
    files,
}: {
    files: ProvisioningAttachment[];
}) {
    if (!files.length) return null;

    return (
        <section aria-label="Submitted files">
            <h3 className="text-section-title">Submitted files</h3>
            <ul className="mt-3 space-y-2">
                {files.map((file) => (
                    <li
                        key={file.id}
                        className="rounded-lg border border-border p-3"
                    >
                        <a
                            href={file.url}
                            target="_blank"
                            rel="noreferrer"
                            aria-label={`${file.name} (opens in a new tab)`}
                            className="frontline-focus inline-flex min-h-11 max-w-full items-center gap-2 rounded-sm text-sm font-semibold text-primary hover:underline"
                        >
                            <Paperclip
                                aria-hidden="true"
                                className="h-4 w-4 shrink-0"
                            />
                            <span className="min-w-0 break-all">
                                {file.name}
                            </span>
                        </a>
                        <div className="text-caption mt-1 flex flex-wrap items-center gap-2">
                            <span>
                                {file.catalogue_field_label} ·{' '}
                                {formatFileSize(file.size)}
                            </span>
                            {file.is_internal && (
                                <StatusBadge variant="warning">
                                    Internal IT
                                </StatusBadge>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** A read-only entry remains available for completed work and view-only staff. */
export function ProvisioningRequestFiles({
    item,
    files = [],
}: {
    item: string;
    files?: ProvisioningAttachment[];
}) {
    if (!files.length) return null;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    className="frontline-focus min-h-11"
                    aria-label={`View ${files.length} submitted files for ${item}`}
                    onClick={(event) => event.stopPropagation()}
                >
                    <Paperclip aria-hidden="true" className="h-4 w-4" />
                    Files ({files.length})
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Request files</DialogTitle>
                    <DialogDescription>{item}</DialogDescription>
                </DialogHeader>
                <ProvisioningSubmittedFiles files={files} />
            </DialogContent>
        </Dialog>
    );
}
