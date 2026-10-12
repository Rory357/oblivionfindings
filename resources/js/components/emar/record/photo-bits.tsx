import { AlertTriangle, ArrowUpRight, ImageOff } from 'lucide-react';
import { useState } from 'react';

import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';

import type { RecordPackPhotoSummary } from './types';

const GUIDE_ONLY = 'Check the label — the picture is a guide only.';

/**
 * The approved P02 "Pack photo" column (Lane C11): when the latest staff
 * photo was taken, a warning when the pack or brand changed, or "No photo —
 * check the label". A catalogue picture reads as a reference image.
 */
export function PackPhotoCell({
    photo,
}: {
    photo: RecordPackPhotoSummary | null | undefined;
}) {
    if (!photo)
        return (
            <span className="inline-flex items-center gap-1 text-[12px] text-muted-foreground">
                <ImageOff className="size-3.5" aria-hidden="true" /> No photo —
                check the label
            </span>
        );
    if (photo.kind === 'reference')
        return <span className="text-[12px]">Reference image only</span>;
    if (photo.pack_changed)
        return (
            <StatusBadge variant="warning">
                <AlertTriangle className="size-3" aria-hidden="true" /> Pack or
                brand changed
            </StatusBadge>
        );
    return (
        <span className="text-[12px]">
            Photo{' '}
            {photo.taken_at ? formatDateOnly(photo.taken_at) : 'date unknown'}
        </span>
    );
}

/**
 * Medicine details › Pack photo & supply (approved P02 v1): the photo staff
 * see when recording, who took it and from which pack, and a way into the
 * photo history.
 */
export function MedicinePackPhoto({
    name,
    photo,
    onHistory,
}: {
    name: string;
    photo: RecordPackPhotoSummary | null;
    onHistory?: () => void;
}) {
    const [file, setFile] = useState<PreviewFile | null>(null);
    if (!photo)
        return (
            <div className="flex items-start gap-3 rounded-lg border border-dashed p-3">
                <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <ImageOff className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 text-sm">
                    <p className="font-semibold">No photo — check the label</p>
                    <p className="text-caption">
                        A photo can be added when stock is received.
                    </p>
                </div>
            </div>
        );
    const reference = photo.kind === 'reference';
    const line = reference
        ? `Reference image · ${photo.source ?? 'catalogue'} · not a photo of this pack`
        : [
              photo.taken_at
                  ? `Taken ${formatDateTime(photo.taken_at)}${photo.taken_by ? ` by ${photo.taken_by}` : ''}`
                  : null,
              photo.pack,
              photo.pharmacy,
          ]
              .filter(Boolean)
              .join(' · ');
    return (
        <div className="space-y-3">
            <div className="flex items-start gap-3">
                <Button
                    unstyled
                    type="button"
                    className="shrink-0 rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={`View the pack photo of ${name}`}
                    onClick={() =>
                        setFile({
                            id: photo.url,
                            name: `${name} — ${reference ? 'reference image' : 'photo of the supplied pack'}`,
                            mime: 'image/jpeg',
                            source: line,
                            previewUrl: photo.url,
                            downloadUrl: reference ? null : photo.url,
                        })
                    }
                >
                    <img
                        src={photo.url}
                        alt=""
                        className="size-24 rounded-lg border object-cover"
                    />
                </Button>
                <div className="min-w-0 space-y-1 text-sm">
                    <p className="font-semibold">
                        {reference
                            ? 'Reference image'
                            : 'Photo of the supplied pack'}
                    </p>
                    <p className="text-caption">{line}</p>
                    {photo.pack_changed ? (
                        <StatusBadge variant="warning">
                            Pack or brand changed — check the label
                        </StatusBadge>
                    ) : null}
                    {photo.now_pack ? (
                        <p className="text-caption">
                            Doses now come from {photo.now_pack}, which may look
                            different.
                        </p>
                    ) : null}
                    <p className="text-caption">{GUIDE_ONLY}</p>
                </div>
            </div>
            {onHistory && !reference ? (
                <Button
                    variant="link"
                    className="h-auto p-0"
                    onClick={onHistory}
                >
                    Open the photo history{' '}
                    <ArrowUpRight className="size-4" aria-hidden="true" />
                </Button>
            ) : null}
            <FilePreviewDialog file={file} onClose={() => setFile(null)} />
        </div>
    );
}
