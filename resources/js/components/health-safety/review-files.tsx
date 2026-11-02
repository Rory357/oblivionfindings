/**
 * G3 board M5d (approved by Stephan 1 Nov 2026; decision 22): photos, videos and documents on a safety review's step.
 * "Add files" uploads on the built drop zone with each file's progress; videos up to 2 minutes (500 MB), other files up
 * to 20 MB; photos, videos, PDFs and Word files only. Opening or playing one is logged by the server; removing one
 * hides it with a reason (never a hard delete), only while the review is open.
 */
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { EyeOff, FileText, Film, Image as ImageIcon, Paperclip, Play, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import { PopupCallout, PopupField, PopupForm, PopupViewer } from '@/components/popup';
import { Surface } from '@/components/signature/blocks';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/datetime';

export type ReviewFile = {
    id: number;
    step: string;
    kind: 'photo' | 'video' | 'document';
    name: string;
    size: number;
    duration: number | null;
    description: string | null;
    uploaded_by: string;
    created_at: string | null;
    url: string;
    can_hide: boolean;
};

const VIDEO_MAX_SECONDS = 120;
const VIDEO_MAX_BYTES = 500 * 1024 * 1024;
const FILE_MAX_BYTES = 20 * 1024 * 1024;
const EXTENSIONS: Record<ReviewFile['kind'], string[]> = {
    photo: ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
    video: ['mp4', 'mov', 'webm', 'm4v'],
    document: ['pdf', 'doc', 'docx'],
};
const ACCEPT = Object.values(EXTENSIONS)
    .flat()
    .map((ext) => `.${ext}`)
    .join(',');

function kindOf(name: string): ReviewFile['kind'] | null {
    const ext = name.split('.').pop()?.toLowerCase() ?? '';
    return (Object.keys(EXTENSIONS) as ReviewFile['kind'][]).find((kind) => EXTENSIONS[kind].includes(ext)) ?? null;
}

export function clock(seconds: number | null | undefined): string {
    if (seconds == null) return '';
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/** "2 photos · 1 video (0:24)" */
export function filesSummary(files: ReviewFile[]): string {
    const photos = files.filter((f) => f.kind === 'photo').length;
    const videos = files.filter((f) => f.kind === 'video');
    const documents = files.filter((f) => f.kind === 'document').length;
    return [
        photos ? `${photos} ${photos === 1 ? 'photo' : 'photos'}` : null,
        videos.length
            ? `${videos.length} ${videos.length === 1 ? 'video' : 'videos'}${videos.length === 1 && videos[0].duration != null ? ` (${clock(videos[0].duration)})` : ''}`
            : null,
        documents ? `${documents} ${documents === 1 ? 'document' : 'documents'}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function videoSeconds(file: File): Promise<number | null> {
    return new Promise((resolve) => {
        try {
            const url = URL.createObjectURL(file);
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.onloadedmetadata = () => {
                URL.revokeObjectURL(url);
                resolve(Number.isFinite(video.duration) ? Math.ceil(video.duration) : null);
            };
            video.onerror = () => {
                URL.revokeObjectURL(url);
                resolve(null);
            };
            video.src = url;
        } catch {
            resolve(null);
        }
    });
}

type Staged = {
    key: string;
    file: File;
    kind: ReviewFile['kind'] | null;
    seconds: number | null;
    state: 'ready' | 'uploading' | 'done' | 'failed' | 'refused';
    progress: number;
    message: string | null;
};

let stagedKey = 0;

/** "Add files" for one step (720). */
export function AddFilesDialog({
    open,
    onClose,
    eventId,
    step,
    stepLabel,
    context,
    viewerName,
}: {
    open: boolean;
    onClose: () => void;
    eventId: number;
    step: string;
    stepLabel: string;
    context: string;
    viewerName?: string | null;
}) {
    const [items, setItems] = useState<Staged[]>([]);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const patch = (key: string, change: Partial<Staged>) =>
        setItems((current) => current.map((item) => (item.key === key ? { ...item, ...change } : item)));

    const stage = async (files: File[]) => {
        for (const file of files) {
            const kind = kindOf(file.name);
            const key = `f${++stagedKey}`;
            let state: Staged['state'] = 'ready';
            let message: string | null = null;
            let seconds: number | null = null;
            if (!kind) {
                state = 'refused';
                message = 'Add photos, videos, PDFs or Word files.';
            } else if (kind === 'video') {
                seconds = await videoSeconds(file);
                if (file.size > VIDEO_MAX_BYTES) {
                    state = 'refused';
                    message = 'Too big: videos can be up to 500 MB.';
                } else if (seconds != null && seconds > VIDEO_MAX_SECONDS) {
                    state = 'refused';
                    message = `Too long: ${clock(seconds)}. Videos can be up to 2 minutes.`;
                }
            } else if (file.size > FILE_MAX_BYTES) {
                state = 'refused';
                message = 'Too big: files can be up to 20 MB.';
            }
            setItems((current) => [...current, { key, file, kind, seconds, state, progress: 0, message }]);
        }
    };

    const upload = async (item: Staged): Promise<boolean> => {
        patch(item.key, { state: 'uploading', progress: 0, message: null });
        const data = new FormData();
        data.append('file', item.file);
        data.append('step', step);
        if (note.trim()) data.append('description', note.trim());
        if (item.seconds != null) data.append('duration_seconds', String(item.seconds));
        try {
            await axios.post(`/health-safety/events/${eventId}/files`, data, {
                headers: { Accept: 'application/json' },
                onUploadProgress: (event) => patch(item.key, { progress: event.total ? Math.round((event.loaded / event.total) * 100) : 0 }),
            });
            patch(item.key, { state: 'done', progress: 100 });
            return true;
        } catch (error) {
            const response = (error as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } }).response;
            const refused = response?.status === 422;
            patch(item.key, {
                state: refused ? 'refused' : 'failed',
                message: refused
                    ? (Object.values(response?.data?.errors ?? {})[0]?.[0] ?? response?.data?.message ?? 'This file wasn’t accepted.')
                    : 'Didn’t upload: the connection dropped',
            });
            return false;
        }
    };

    const ready = items.filter((item) => item.state === 'ready' || item.state === 'failed');

    const submit = async () => {
        setBusy(true);
        let all = true;
        for (const item of ready) all = (await upload(item)) && all;
        setBusy(false);
        router.reload({ only: ['detail'] });
        if (all) {
            setItems([]);
            setNote('');
            onClose();
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={() => {
                if (!busy) {
                    setItems([]);
                    setNote('');
                    onClose();
                }
            }}
            size="md"
            icon={Paperclip}
            title="Add files"
            description="Photos, videos, PDFs or documents for this step"
            context={{ title: `${context} · ${stepLabel}` }}
            submitLabel={ready.length === 1 ? 'Add 1 file' : `Add ${ready.length} files`}
            processingLabel="Uploading…"
            processing={busy}
            submitDisabled={!ready.length}
            onSubmit={submit}
            dirty={items.length > 0 || note !== ''}
            draftName="these files"
            footerStart={viewerName ? <span className="text-caption">Saved as {viewerName}</span> : undefined}
            testId="add-files-dialog"
        >
            <FileDropzone
                onFiles={(files) => void stage(files)}
                accept={ACCEPT}
                title="Drag photos, videos or documents here or browse from your computer"
                hint="Photos, PDF, Word · up to 20 MB each · videos up to 2 minutes (500 MB)"
                disabled={busy}
            />
            {items.length ? (
                <ul className="grid gap-2">
                    {items.map((item) => (
                        <li key={item.key} className="flex items-center gap-3 rounded-lg border border-border px-3 py-2 text-sm">
                            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true">
                                {item.kind === 'video' ? <Film className="size-4" /> : item.kind === 'photo' ? <ImageIcon className="size-4" /> : <FileText className="size-4" />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-semibold">{item.file.name}</p>
                                <p className={item.state === 'refused' || item.state === 'failed' ? 'text-caption text-status-critical' : 'text-caption'}>
                                    {item.message ??
                                        [
                                            item.kind === 'video' ? 'Video' : item.kind === 'photo' ? 'Photo' : 'Document',
                                            item.seconds != null ? clock(item.seconds) : null,
                                            formatFileSize(item.file.size),
                                            item.state === 'uploading' ? `uploading ${item.progress}%` : item.state === 'done' ? 'added' : 'ready',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                </p>
                            </div>
                            {item.state === 'failed' ? (
                                // eslint-disable-next-line no-restricted-syntax -- a quiet retry link inside a file row.
                                <button type="button" className="sg-pane-link" onClick={() => void upload(item)}>
                                    <RotateCcw aria-hidden="true" />
                                    Retry
                                </button>
                            ) : item.state !== 'uploading' && item.state !== 'done' ? (
                                // eslint-disable-next-line no-restricted-syntax -- a quiet remove link inside a file row.
                                <button
                                    type="button"
                                    className="sg-pane-link"
                                    onClick={() => setItems((current) => current.filter((other) => other.key !== item.key))}
                                >
                                    Remove
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            <PopupField label="What do these show? (optional)" htmlFor="add-files-note">
                <Textarea id="add-files-note" rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
            </PopupField>
            <PopupCallout tone="info">
                Videos and photos of a person are health information. Only add them when they’re needed for this review. Only
                people allowed to see this record can open them, and each open is logged.
            </PopupCallout>
        </PopupForm>
    );
}

/** Play a video or see a photo (480). Opening it is logged by the server. */
export function FileViewer({ file, stepLabel, onClose }: { file: ReviewFile; stepLabel: string; onClose: () => void }) {
    return (
        <PopupViewer
            open
            onClose={onClose}
            icon={file.kind === 'video' ? Film : file.kind === 'photo' ? ImageIcon : FileText}
            title={file.name}
            description={[
                file.kind === 'video' ? 'Video' : file.kind === 'photo' ? 'Photo' : 'Document',
                file.duration != null ? clock(file.duration) : null,
                `added by ${file.uploaded_by}${file.created_at ? `, ${formatDate(file.created_at)}` : ''}`,
            ]
                .filter(Boolean)
                .join(' · ')}
            facts={[
                ['What it shows', file.description ?? 'Not said.'],
                ['Step', stepLabel],
                ['Who can open it', 'People allowed to see this record. Each open is kept in the activity log.'],
            ]}
            testId="review-file-viewer"
        >
            {file.kind === 'video' ? (
                <video controls preload="metadata" className="w-full rounded-lg bg-muted" src={file.url}>
                    <track kind="captions" />
                </video>
            ) : file.kind === 'photo' ? (
                <img src={file.url} alt={file.description ?? file.name} className="w-full rounded-lg object-contain" />
            ) : null}
            {file.kind === 'video' ? (
                <p className="text-caption">Not included in the PDF; listed there instead.</p>
            ) : null}
        </PopupViewer>
    );
}

/** Hide a file with a reason (kept, never deleted). */
export function HideFileDialog({ eventId, file, onClose }: { eventId: number; file: ReviewFile; onClose: () => void }) {
    const form = useForm<{ reason: string }>({ reason: '' });
    return (
        <PopupForm
            open
            onClose={onClose}
            icon={EyeOff}
            title="Hide this file"
            description="It stays with the review’s history; only its reason shows."
            context={{ title: file.name }}
            submitLabel="Hide file"
            processing={form.processing}
            submitDisabled={form.data.reason.trim().length < 10}
            onSubmit={() =>
                form.post(`/health-safety/events/${eventId}/files/${file.id}/hide`, { preserveScroll: true, onSuccess: () => onClose() })
            }
            dirty={form.isDirty}
        >
            <PopupField label="Why hide it?" htmlFor="hide-file-why" required hint="At least 10 characters." error={form.errors.reason}>
                <Textarea id="hide-file-why" rows={3} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </PopupField>
        </PopupForm>
    );
}

/** The review's own files on the Files tab, by step, with open / play and hide. */
export function ReviewFilesSurface({
    eventId,
    files,
    stepLabel,
    canAdd,
    onAdd,
}: {
    eventId: number;
    files: ReviewFile[];
    stepLabel: (step: string) => string;
    canAdd: boolean;
    onAdd: () => void;
}) {
    const [viewing, setViewing] = useState<ReviewFile | null>(null);
    const [hiding, setHiding] = useState<ReviewFile | null>(null);
    return (
        <Surface
            title="Files on this safety review"
            sub={files.length ? filesSummary(files) : 'Photos, videos and documents added to its steps'}
            action={canAdd ? { label: 'Add files', onClick: onAdd } : null}
        >
            {files.length ? (
                <ul className="grid">
                    {files.map((file) => (
                        <li key={file.id} className="flex flex-wrap items-center gap-3 border-t border-border px-[18px] py-3 first:border-t-0">
                            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true">
                                {file.kind === 'video' ? <Film className="size-4" /> : file.kind === 'photo' ? <ImageIcon className="size-4" /> : <FileText className="size-4" />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-semibold">{file.name}</p>
                                <p className="text-caption">
                                    {[stepLabel(file.step), file.duration != null ? clock(file.duration) : null, formatFileSize(file.size), `added by ${file.uploaded_by}`]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {file.description ? <p className="text-sm text-muted-foreground">{file.description}</p> : null}
                            </div>
                            {file.kind === 'document' ? (
                                <a className="sg-pane-link" href={file.url}>
                                    <FileText aria-hidden="true" />
                                    Open
                                </a>
                            ) : (
                                // eslint-disable-next-line no-restricted-syntax -- a quiet open/play link in a file row.
                                <button type="button" className="sg-pane-link" onClick={() => setViewing(file)}>
                                    {file.kind === 'video' ? <Play aria-hidden="true" /> : <ImageIcon aria-hidden="true" />}
                                    {file.kind === 'video' ? 'Play' : 'View'}
                                </button>
                            )}
                            {file.can_hide ? (
                                // eslint-disable-next-line no-restricted-syntax -- a quiet hide link in a file row.
                                <button type="button" className="sg-pane-link" onClick={() => setHiding(file)}>
                                    <EyeOff aria-hidden="true" />
                                    Hide
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="sg-pane-empty">No photos, videos or documents yet.</p>
            )}
            {viewing ? <FileViewer file={viewing} stepLabel={stepLabel(viewing.step)} onClose={() => setViewing(null)} /> : null}
            {hiding ? <HideFileDialog eventId={eventId} file={hiding} onClose={() => setHiding(null)} /> : null}
        </Surface>
    );
}
