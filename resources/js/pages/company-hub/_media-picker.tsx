import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Images } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { MediaLibrary, type HubMedia } from './_media-library';

export function MediaUpload({
    pageId,
    mediaId,
    onChange,
    label,
    file = false,
}: {
    pageId: number;
    mediaId?: number | null;
    onChange: (id: number | null, media?: HubMedia) => void;
    label: string;
    file?: boolean;
}) {
    const [uploading, setUploading] = useState(false),
        [error, setError] = useState('');
    const [open, setOpen] = useState(false),
        [dirty, setDirty] = useState(false);
    const latest = useRef({ pageId, onChange }),
        alive = useRef(true);
    latest.current = { pageId, onChange };
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">{label}</p>
            {!!mediaId && (
                <div className="space-y-2">
                    {file ? (
                        <a
                            href={`/company-hub/media/${mediaId}`}
                            className="text-sm text-primary underline"
                        >
                            View selected file
                        </a>
                    ) : (
                        <img
                            src={`/company-hub/media/${mediaId}`}
                            alt="Selected page image"
                            className="max-h-36 w-full rounded-lg object-cover"
                        />
                    )}
                    <Button
                        disabled={uploading}
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(null)}
                    >
                        Remove {file ? 'file' : 'image'}
                    </Button>
                </div>
            )}
            <Button
                size="sm"
                variant="outline"
                disabled={!pageId || uploading}
                onClick={() => setOpen(true)}
            >
                <Images className="size-4" /> Media library
            </Button>
            <FileDropzone
                multiple={false}
                accept={
                    file
                        ? 'application/pdf,image/jpeg,image/png,image/webp,image/gif'
                        : 'image/jpeg,image/png,image/webp,image/gif'
                }
                disabled={uploading || !pageId}
                hint={
                    !pageId
                        ? 'Create your own page to upload files.'
                        : file
                          ? 'PDF or image · up to 10 MB'
                          : 'JPG, PNG, WebP or GIF · up to 10 MB'
                }
                onFiles={async (files) => {
                    if (!files[0]) return;
                    if (files[0].size > 10 * 1024 * 1024) {
                        setError('Choose a file smaller than 10 MB.');
                        return;
                    }
                    setUploading(true);
                    setError('');
                    const form = new FormData();
                    form.append('file', files[0]);
                    try {
                        const media = await hubRequest<HubMedia>(
                            `/company-hub/manage/${pageId}/media`,
                            'POST',
                            form,
                        );
                        if (alive.current && latest.current.pageId === pageId)
                            latest.current.onChange(media.id, media);
                    } catch (e) {
                        if (alive.current)
                            setError(
                                e instanceof Error
                                    ? e.message
                                    : 'Upload failed. Please try again.',
                            );
                    } finally {
                        if (alive.current) setUploading(false);
                    }
                }}
            />
            {uploading && (
                <p role="status" className="text-caption text-muted-foreground">
                    Uploading…
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <Dialog
                open={open}
                onOpenChange={(value) => !dirty && setOpen(value)}
            >
                <DialogContent
                    className="hub-media-library-dialog"
                    onEscapeKeyDown={(e) => dirty && e.preventDefault()}
                    onInteractOutside={(e) => dirty && e.preventDefault()}
                >
                    <DialogHeader className="sr-only">
                        <DialogTitle>Choose from the media library</DialogTitle>
                        <DialogDescription>
                            Manage albums, image descriptions and crops, then
                            choose a file for this page.
                        </DialogDescription>
                    </DialogHeader>
                    {open && (
                        <MediaLibrary
                            pageId={pageId}
                            file={file}
                            selectedId={mediaId}
                            onDirtyChange={setDirty}
                            onSelect={(media) => {
                                latest.current.onChange(media.id, media);
                                setOpen(false);
                            }}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
