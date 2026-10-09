/* eMAR audit Lane A11 — the approved P01 v2 "Photo of the supplied pack" card
 * on the Safety checks step. Staff photos of the pack actually supplied are
 * the core feature; a catalogue picture shows only when configured and is
 * labelled as a reference image. The card never blocks recording unless the
 * organisation asks for "I’ve checked the label" when there's no photo. */
import {
    FilePreviewDialog,
    permittedFileUrl,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatDateLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { AlertTriangle, ImageOff } from 'lucide-react';
import { useState } from 'react';
import type { PackPhoto, PackPhotoPolicy } from './types';

const GUIDE_ONLY = 'Check the label — the picture is a guide only.';

/** "Taken 3 October 2026 by Aroha Ngata · Box of 28 · Unichem Riccarton" */
export function packPhotoLine(photo: PackPhoto): string {
    const parts = [
        photo.taken_at
            ? `Taken ${formatDateLong(photo.taken_at)}${photo.taken_by ? ` by ${photo.taken_by}` : ''}`
            : photo.taken_by
              ? `Taken by ${photo.taken_by}`
              : null,
        photo.pack,
        photo.pharmacy,
    ];
    return parts.filter(Boolean).join(' · ');
}

/** Does the Safety checks step still need the "checked the label" tick? */
export function packLabelTickNeeded(
    photo: PackPhoto | null | undefined,
    policy: PackPhotoPolicy | undefined,
): boolean {
    return (
        photo === null && policy?.show !== false && policy?.no_photo === 'tick'
    );
}

export function PackPhotoCard({
    photo,
    policy,
    medicine,
    labelChecked,
    onLabelChecked,
}: {
    /** undefined: the server doesn't send photos yet — nothing is shown. */
    photo: PackPhoto | null | undefined;
    policy: PackPhotoPolicy | undefined;
    medicine: string;
    labelChecked: boolean;
    onLabelChecked: (checked: boolean) => void;
}) {
    const [open, setOpen] = useState<PreviewFile | null>(null);
    if (photo === undefined || policy?.show === false) return null;

    if (photo === null) {
        const tick = policy?.no_photo === 'tick';
        return (
            <div
                className="flex w-full items-start gap-3 rounded-lg border border-dashed border-status-warning/50 bg-status-warning-bg p-3"
                data-field={tick ? 'labelChecked' : undefined}
            >
                <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <ImageOff className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 space-y-2 text-sm">
                    <div>
                        <p className="font-semibold text-status-warning">
                            No photo — check the label
                        </p>
                        <p className="text-subtle">
                            {policy?.receipt_prompt
                                ? 'A photo can be added when stock is received. '
                                : ''}
                            {tick
                                ? 'Tick when you’ve checked the label against the order.'
                                : 'It’s optional and never blocks recording.'}
                        </p>
                    </div>
                    {tick ? (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="rd-label-checked"
                                checked={labelChecked}
                                onCheckedChange={(v) =>
                                    onLabelChecked(v === true)
                                }
                            />
                            <Label
                                htmlFor="rd-label-checked"
                                className="text-sm font-normal"
                            >
                                I’ve checked the label{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                        </div>
                    ) : null}
                </div>
            </div>
        );
    }

    const reference = photo.kind === 'reference';
    const changed = !reference && photo.pack_changed === true;
    const url = permittedFileUrl(photo.url);
    const title = reference
        ? 'Reference image — not a photo of this pack'
        : 'Photo of the supplied pack';
    const line = reference
        ? (photo.source ?? 'From the configured medicine catalogue')
        : packPhotoLine(photo);
    return (
        <div
            className={cn(
                'flex w-full items-start gap-3 rounded-lg border p-3',
                changed
                    ? 'border-status-warning/50 bg-status-warning-bg'
                    : 'bg-muted/30',
            )}
        >
            {url ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap relative size-16 shrink-0 overflow-hidden rounded-lg p-0"
                    aria-label={`View the ${reference ? 'reference image' : 'photo'} of ${medicine} larger`}
                    onClick={() =>
                        setOpen({
                            id: url,
                            name: `${medicine} — ${reference ? 'reference image' : 'photo of the supplied pack'}`,
                            mime: 'image/jpeg',
                            source: line || null,
                            previewUrl: url,
                            downloadUrl: null,
                        })
                    }
                >
                    <img
                        src={url}
                        alt=""
                        className={cn(
                            'size-full object-cover',
                            changed && 'opacity-60 grayscale',
                        )}
                    />
                </Button>
            ) : null}
            <div className="min-w-0 text-sm">
                {changed ? (
                    <p className="flex items-center gap-1.5 font-semibold text-status-warning">
                        <AlertTriangle
                            className="size-3.5"
                            aria-hidden="true"
                        />
                        Pack or brand changed — check the label
                    </p>
                ) : (
                    <p className="font-semibold">{title}</p>
                )}
                {line ? (
                    <p className="text-subtle">
                        {line}
                        {changed && photo.now_pack
                            ? ` · now ${photo.now_pack}`
                            : ''}
                    </p>
                ) : null}
                <p className="text-subtle">{GUIDE_ONLY}</p>
            </div>
            <FilePreviewDialog file={open} onClose={() => setOpen(null)} />
        </div>
    );
}
