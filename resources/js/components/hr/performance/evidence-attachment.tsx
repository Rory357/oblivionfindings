/* eslint-disable no-restricted-syntax -- compact inline evidence control reused
 * across the Performance hub detail pages (reviews, goals, PIP milestones,
 * competency assessments). Posts a single file as multipart to a private-disk
 * endpoint; every colour is a semantic design token. */
import { router } from '@inertiajs/react';
import { FileText, Paperclip } from 'lucide-react';
import { useRef, useState } from 'react';

import { cn } from '@/lib/utils';

export function EvidenceAttachment({
    uploadUrl,
    viewUrl,
    hasEvidence,
    canManage,
    disabled,
    className,
}: {
    uploadUrl: string;
    viewUrl: string;
    hasEvidence: boolean;
    canManage: boolean;
    disabled?: boolean;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const upload = (file: File) => {
        const fd = new FormData();
        fd.append('file', file);
        setUploading(true);
        router.post(uploadUrl, fd, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploading(false),
        });
    };

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 text-sm',
                className,
            )}
        >
            {hasEvidence ? (
                <a
                    href={viewUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                >
                    <FileText className="h-3.5 w-3.5" />
                    View evidence
                </a>
            ) : (
                <span className="text-muted-foreground">
                    No evidence attached
                </span>
            )}
            {canManage && !disabled && (
                <>
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        disabled={uploading}
                        className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1 text-xs font-semibold disabled:opacity-50"
                    >
                        <Paperclip className="h-3.5 w-3.5" />
                        {uploading
                            ? 'Uploading…'
                            : hasEvidence
                              ? 'Replace'
                              : 'Attach evidence'}
                    </button>
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        className="hidden"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) upload(f);
                            e.target.value = '';
                        }}
                    />
                </>
            )}
        </div>
    );
}

export default EvidenceAttachment;
