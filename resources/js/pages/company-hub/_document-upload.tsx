import { Button } from '@/components/ui/button';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    FileText,
    Loader2,
    RefreshCw,
    ShieldCheck,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError } from './_api';
import {
    documentFileError,
    fileSize,
    hubDocumentAccept,
    hubDocumentHint,
    uploadHubFile,
} from './_upload';

type Receipt = {
    saved: boolean;
    actor_id: number;
    request_id: string;
    document_id: number;
    media_id: number;
    page_id: number;
    page_version: number;
    edit_url: string;
    details_url?: string;
    reader_url: string;
};
type QueuedFile = {
    id: string;
    file: File;
    title: string;
    description: string;
    status: 'queued' | 'uploading' | 'done' | 'error' | 'cancelled';
    progress: number;
    error?: string;
    receipt?: Receipt;
    attempted?: boolean;
};

export function DocumentUploadWorkspace({ onClose }: { onClose: () => void }) {
    const actor = (
        usePage().props.auth as { user?: { id: number } } | undefined
    )?.user?.id;
    return <UploadBody key={actor} actor={actor} onClose={onClose} />;
}
function UploadBody({
    actor,
    onClose,
}: {
    actor?: number;
    onClose: () => void;
}) {
    const [items, setItems] = useState<QueuedFile[]>([]),
        [running, setRunning] = useState(false),
        [error, setError] = useState('');
    const current = useRef(items),
        alive = useRef(true),
        pending = useRef(false),
        controller = useRef<AbortController | null>(null),
        stop = useRef(false);
    current.current = items;
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            stop.current = true;
            controller.current?.abort();
        };
    }, []);
    const dirty = items.some((item) => item.status !== 'done');
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Leave document uploads?',
        'Files already uploaded remain private drafts. Files still in this queue will be removed from the queue.',
        () => {
            stop.current = true;
            controller.current?.abort();
        },
        running,
    );
    const update = (id: string, patch: Partial<QueuedFile>) => {
        if (alive.current)
            setItems((previous) =>
                previous.map((item) =>
                    item.id === id ? { ...item, ...patch } : item,
                ),
            );
    };
    async function run(ids?: string[]) {
        if (!actor || pending.current) return;
        pending.current = true;
        stop.current = false;
        setRunning(true);
        setError('');
        const queue = current.current.filter((item) =>
            ids ? ids.includes(item.id) : item.status === 'queued',
        );
        for (const item of queue) {
            if (stop.current || !alive.current) break;
            if (!item.title.trim()) {
                update(item.id, {
                    status: 'error',
                    error: 'Add a document title before uploading.',
                });
                continue;
            }
            const form = new FormData();
            form.append('file', item.file);
            form.append('title', item.title.trim());
            form.append('description', item.description);
            form.append('actor_id', String(actor));
            form.append('request_id', item.id);
            controller.current = new AbortController();
            update(item.id, {
                status: 'uploading',
                progress: 0,
                error: '',
                attempted: true,
            });
            try {
                const receipt = await uploadHubFile<Receipt>(
                    '/company-hub/documents',
                    form,
                    controller.current.signal,
                    (progress) => update(item.id, { progress }),
                );
                if (
                    !receipt ||
                    receipt.saved !== true ||
                    receipt.actor_id !== actor ||
                    receipt.request_id !== item.id ||
                    !Number.isSafeInteger(receipt.page_id) ||
                    receipt.page_id <= 0 ||
                    !Number.isSafeInteger(receipt.document_id) ||
                    receipt.document_id <= 0 ||
                    receipt.edit_url !==
                        `/company-hub/manage/${receipt.page_id}/edit` ||
                    (receipt.details_url !== undefined &&
                        receipt.details_url !==
                            `/company-hub/manage/${receipt.page_id}/documents/${receipt.document_id}/edit`)
                )
                    throw new Error(
                        'The saved draft could not be confirmed. Retry to recover the same upload.',
                    );
                update(item.id, { status: 'done', progress: 100, receipt });
            } catch (failure) {
                update(item.id, {
                    ...(failure instanceof HubRequestError &&
                    failure.status === 422
                        ? { id: crypto.randomUUID(), attempted: false }
                        : {}),
                    status: controller.current.signal.aborted
                        ? 'cancelled'
                        : 'error',
                    error:
                        failure instanceof Error
                            ? failure.message
                            : 'Upload failed. Retry this file.',
                });
            }
        }
        pending.current = false;
        controller.current = null;
        if (alive.current) setRunning(false);
    }
    return (
        <section
            className="hub-document-upload space-y-6"
            aria-label="Upload documents"
        >
            <Button variant="ghost" onClick={() => leave.request(onClose)}>
                <ArrowLeft className="size-4" /> Back to the library
            </Button>
            <div className="hub-discovery-heading">
                <div>
                    <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                        Company knowledge
                    </p>
                    <h2>Give useful documents a home.</h2>
                    <p>
                        Upload files, add a clear title, then review each
                        document before sharing it with staff.
                    </p>
                </div>
                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                    <ShieldCheck className="size-4" /> Private until published
                </span>
            </div>
            <div className="hub-upload-layout">
                <div className="space-y-4">
                    <FileDropzone
                        accept={hubDocumentAccept}
                        multiple
                        disabled={running}
                        hint={hubDocumentHint}
                        onFiles={(files) => {
                            const next: QueuedFile[] = [];
                            const errors: string[] = [];
                            for (const file of files) {
                                const invalid = documentFileError(file);
                                if (invalid) {
                                    errors.push(`${file.name}: ${invalid}`);
                                    continue;
                                }
                                if (
                                    current.current.length + next.length >=
                                    20
                                ) {
                                    errors.push(
                                        'Add up to 20 files at a time.',
                                    );
                                    break;
                                }
                                next.push({
                                    id: crypto.randomUUID(),
                                    file,
                                    title: file.name
                                        .replace(/\.[^.]+$/, '')
                                        .replace(/[_-]+/g, ' ')
                                        .slice(0, 160),
                                    description: '',
                                    status: 'queued',
                                    progress: 0,
                                });
                            }
                            setItems((previous) => [...previous, ...next]);
                            setError(errors.join(' '));
                        }}
                    />
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <div className="space-y-3">
                        {items.map((item) => (
                            <article key={item.id} className="hub-upload-file">
                                <div className="flex items-center gap-3">
                                    <span className="hub-document-icon">
                                        <FileText className="size-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {item.file.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {fileSize(item.file.size)} ·{' '}
                                            {item.status === 'done'
                                                ? 'Draft created'
                                                : item.status === 'uploading'
                                                  ? item.progress === 100
                                                      ? 'Checking and saving…'
                                                      : `Uploading ${item.progress}%`
                                                  : item.status === 'queued'
                                                    ? 'Ready to upload'
                                                    : 'Needs attention'}
                                        </p>
                                    </div>
                                    {item.status === 'done' ? (
                                        <Check className="size-5 text-primary" />
                                    ) : item.status === 'uploading' ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                stop.current = true;
                                                controller.current?.abort();
                                            }}
                                        >
                                            Cancel upload
                                        </Button>
                                    ) : (
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Remove ${item.file.name} from queue`}
                                            disabled={running}
                                            onClick={() =>
                                                setItems((previous) =>
                                                    previous.filter(
                                                        (file) =>
                                                            file.id !== item.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    )}
                                </div>
                                {item.status !== 'done' && (
                                    <div className="mt-4 grid gap-3">
                                        <label className="space-y-1 text-xs font-medium">
                                            Document title
                                            <Input
                                                value={item.title}
                                                maxLength={160}
                                                disabled={
                                                    running || item.attempted
                                                }
                                                onChange={(event) =>
                                                    update(item.id, {
                                                        title: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </label>
                                        <label className="space-y-1 text-xs font-medium">
                                            What will this help people do?
                                            <Textarea
                                                value={item.description}
                                                maxLength={500}
                                                rows={2}
                                                disabled={
                                                    running || item.attempted
                                                }
                                                onChange={(event) =>
                                                    update(item.id, {
                                                        description:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="A short description makes this easier to find."
                                            />
                                        </label>
                                    </div>
                                )}
                                {item.status === 'uploading' && (
                                    <progress
                                        className="mt-3 h-1.5 w-full accent-primary"
                                        value={item.progress}
                                        max={100}
                                        aria-label={`Upload progress for ${item.file.name}`}
                                    />
                                )}
                                {item.error && (
                                    <p
                                        role="alert"
                                        className="mt-3 text-sm text-destructive"
                                    >
                                        {item.error}
                                    </p>
                                )}
                                {['error', 'cancelled'].includes(
                                    item.status,
                                ) && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-3"
                                        disabled={running}
                                        onClick={() => void run([item.id])}
                                    >
                                        <RefreshCw className="size-4" /> Retry
                                        or recover upload
                                    </Button>
                                )}
                                {item.receipt && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-3"
                                        disabled={running}
                                        onClick={() =>
                                            leave.request(() =>
                                                router.visit(
                                                    item.receipt!.details_url ??
                                                        item.receipt!.edit_url,
                                                ),
                                            )
                                        }
                                    >
                                        Review draft{' '}
                                        <ArrowRight className="size-4" />
                                    </Button>
                                )}
                            </article>
                        ))}
                    </div>
                    {!!items.length && (
                        <div className="flex items-center justify-between gap-3">
                            <p
                                className="text-sm text-muted-foreground"
                                role="status"
                            >
                                {
                                    items.filter(
                                        (item) => item.status === 'done',
                                    ).length
                                }{' '}
                                of {items.length} documents saved as drafts
                            </p>
                            <Button
                                disabled={
                                    running ||
                                    !actor ||
                                    !items.some(
                                        (item) => item.status === 'queued',
                                    ) ||
                                    items.some(
                                        (item) =>
                                            item.status === 'queued' &&
                                            !item.title.trim(),
                                    )
                                }
                                onClick={() => void run()}
                            >
                                {running ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                {running ? 'Uploading…' : 'Upload documents'}
                            </Button>
                        </div>
                    )}
                </div>
                <aside className="hub-document-aside">
                    <h3>From file to useful resource</h3>
                    <ol className="space-y-5">
                        <li>
                            <strong>1. Add your documents</strong>
                            <p>
                                Each file gets its own document draft with you
                                as its owner.
                            </p>
                        </li>
                        <li>
                            <strong>2. Make it easy to find</strong>
                            <p>
                                Review its description, collection, audience and
                                next review date.
                            </p>
                        </li>
                        <li>
                            <strong>3. Share when it is ready</strong>
                            <p>
                                Review, submit or publish in its document
                                workspace. Uploading alone does not publish a
                                file.
                            </p>
                        </li>
                    </ol>
                    <p className="mt-6 border-t pt-4">
                        A cancelled upload may already have reached the server.
                        Retry recovers the same draft using this upload’s
                        reference.
                    </p>
                </aside>
            </div>
            {leave.confirmation}
        </section>
    );
}
