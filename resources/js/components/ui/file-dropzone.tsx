/* eslint-disable no-restricted-syntax -- Bespoke upload chrome (drag-and-drop zone +
 * staged-file cards) shared across the Add Site / Safeguarding / Incident modals;
 * custom layout surfaces with semantic design tokens only, never hardcoded colours. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { FileText, Trash2, Upload, UploadCloud } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type AriaAttributes, type ReactNode } from 'react';

export function formatFileSize(bytes: number): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Premium drag-and-drop zone (chrome only) — emits the chosen files via onFiles.
 * Extracted from the Add Site documents step so every modal shares one look.
 */
export function FileDropzone({
    id,
    'aria-labelledby': labelledBy,
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    onFiles,
    accept,
    multiple = true,
    title = 'Drag & drop files here',
    hint = 'PDF, Word, images',
    disabled = false,
}: {
    id?: string;
    'aria-labelledby'?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: AriaAttributes['aria-invalid'];
    onFiles: (files: File[]) => void;
    accept?: string;
    multiple?: boolean;
    title?: string;
    hint?: string;
    disabled?: boolean;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const emit = (list: FileList | null) => {
        if (!list || list.length === 0) return;
        onFiles(Array.from(list));
        if (inputRef.current) inputRef.current.value = '';
    };

    return (
        <>
            <div
                id={id}
                role="button"
                tabIndex={disabled ? -1 : 0}
                aria-disabled={disabled}
                aria-labelledby={labelledBy}
                aria-describedby={describedBy}
                aria-invalid={invalid}
                onClick={() => !disabled && inputRef.current?.click()}
                onKeyDown={(e) => {
                    if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragEnter={(e) => {
                    e.preventDefault();
                    if (!disabled) setDragging(true);
                }}
                onDragOver={(e) => {
                    e.preventDefault();
                    if (!disabled) setDragging(true);
                }}
                onDragLeave={(e) => {
                    e.preventDefault();
                    if (!e.currentTarget.contains(e.relatedTarget as Node)) setDragging(false);
                }}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    if (!disabled) emit(e.dataTransfer.files);
                }}
                className={cn(
                    'flex cursor-pointer flex-col items-center gap-3 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-all outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    disabled && 'cursor-not-allowed opacity-60',
                    dragging
                        ? 'border-primary bg-primary/10 ring-4 ring-primary/15'
                        : 'border-border bg-muted/30 hover:border-primary/50 hover:bg-muted/40',
                )}
            >
                <span
                    className={cn(
                        'grid h-14 w-14 place-items-center rounded-2xl transition-colors',
                        dragging ? 'bg-primary-fill text-primary-fill-foreground' : 'bg-primary/10 text-primary',
                    )}
                >
                    <UploadCloud className="h-7 w-7" />
                </span>
                <div>
                    <div id={id ? `${id}-title` : undefined} className="text-sm font-semibold">{dragging ? 'Drop files to upload' : title}</div>
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        or <span className="font-semibold text-primary">browse</span> from your computer
                    </div>
                </div>
                {hint ? <div className="text-[11px] text-muted-foreground">{hint}</div> : null}
            </div>
            <input
                id={id ? `${id}-input` : undefined}
                ref={inputRef}
                type="file"
                accept={accept}
                multiple={multiple}
                disabled={disabled}
                aria-labelledby={labelledBy}
                aria-describedby={describedBy}
                aria-invalid={invalid}
                className="hidden"
                onChange={(e) => emit(e.target.files)}
            />
        </>
    );
}

/**
 * Premium staged-file card — image thumbnail (for images) or a file glyph, name,
 * size, a remove button, and an optional per-file metadata row (children).
 */
export function StagedFileCard({ file, onRemove, children }: { file: File; onRemove: () => void; children?: ReactNode }) {
    const preview = useMemo(() => (file.type.startsWith('image/') ? URL.createObjectURL(file) : null), [file]);
    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    return (
        <div className="rounded-xl border border-border bg-card/70 p-3 transition-colors hover:border-primary/40">
            <div className="flex items-center gap-3">
                {preview ? (
                    <img src={preview} alt={file.name} className="h-10 w-10 shrink-0 rounded-lg object-cover" />
                ) : (
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <FileText className="h-5 w-5" />
                    </span>
                )}
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-semibold">{file.name}</div>
                    <div className="text-[11px] text-muted-foreground">{formatFileSize(file.size)}</div>
                </div>
                <button type="button" aria-label="Remove file" onClick={onRemove} className="shrink-0 text-muted-foreground transition-colors hover:text-status-critical">
                    <Trash2 className="h-4 w-4" />
                </button>
            </div>
            {children ? <div className="mt-2.5">{children}</div> : null}
        </div>
    );
}

type StagedItem = { id: number; file: File; note: string; sensitive: boolean; category: string };

let stagedUid = 0;

/**
 * Premium attachment uploader for "record already exists" modals (Safeguarding
 * evidence, Incident photos/documents). Drag/drop or browse multiple files,
 * stage them as premium cards with optional per-file note + sensitive flag, then
 * upload them sequentially to a single-file endpoint. Reuses the same dropzone
 * chrome as the Add Site documents step.
 */
export function AttachmentUploader({
    endpoint,
    noteField = null,
    sensitive = null,
    categoryField = null,
    accept,
    hint,
    onStateChange,
    requestData,
    nextRequestData,
    frontline = false,
}: {
    endpoint: string;
    /** Form field for an optional per-file note (omit to hide the note input). */
    noteField?: string | null;
    /** Optional per-file sensitive toggle: form field + checkbox label. */
    sensitive?: { field: string; label: string } | null;
    /** Optional per-file category select: form field + label + options. */
    categoryField?: { field: string; label: string; options: { value: string; label: string }[] } | null;
    accept?: string;
    hint?: string;
    /** Optional host leave protection; file contents stay in this component. */
    onStateChange?: (state: { dirty: boolean; busy: boolean }) => void;
    /** Optional source-version guards. Sequential uploads advance only from the server receipt. */
    requestData?: Record<string, string | number>;
    nextRequestData?: (page: Page) => Record<string, string | number> | null;
    frontline?: boolean;
}) {
    const [items, setItems] = useState<StagedItem[]>([]);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        onStateChange?.({ dirty: items.length > 0, busy: uploading });
    }, [items.length, uploading, onStateChange]);
    useEffect(() => () => onStateChange?.({ dirty: false, busy: false }), [onStateChange]);

    const add = (files: File[]) => {
        setError(null);
        setItems((prev) => [...prev, ...files.map((file) => ({ id: ++stagedUid, file, note: '', sensitive: false, category: '' }))]);
    };
    const patch = (id: number, p: Partial<StagedItem>) => setItems((prev) => prev.map((it) => (it.id === id ? { ...it, ...p } : it)));
    const remove = (id: number) => setItems((prev) => prev.filter((it) => it.id !== id));

    // Sequential upload — each file posts to the single-file endpoint, dropping out
    // of the staged list as it lands so the remaining files read as a progress queue.
    const upload = () => {
        if (!items.length || uploading) return;
        setUploading(true);
        setError(null);
        const queue = [...items];
        let guards = requestData;
        const next = (i: number) => {
            if (i >= queue.length) {
                setUploading(false);
                return;
            }
            const it = queue[i];
            const fd = new FormData();
            fd.append('file', it.file);
            if (noteField) fd.append(noteField, it.note);
            if (sensitive) fd.append(sensitive.field, it.sensitive ? '1' : '0');
            if (categoryField && it.category) fd.append(categoryField.field, it.category);
            Object.entries(guards ?? {}).forEach(([key, value]) => fd.append(key, String(value)));
            let receivedResponse = false;
            router.post(endpoint, fd, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    receivedResponse = true;
                    const flash = page.props.flash as { error?: string } | undefined;
                    if (flash?.error) {
                        setError(flash.error);
                        setUploading(false);
                        return;
                    }
                    remove(it.id);
                    if (nextRequestData) {
                        const nextGuards = nextRequestData(page);
                        if (nextGuards === null && i + 1 < queue.length) {
                            setError('The file was saved, but the updated version could not be confirmed. Check the record before uploading the remaining files.');
                            setUploading(false);
                            return;
                        }
                        guards = nextGuards ?? undefined;
                    }
                    next(i + 1);
                },
                onError: (errors) => {
                    receivedResponse = true;
                    setError(Object.values(errors).join(' ') || 'Upload failed. Your remaining files are still here.');
                    setUploading(false);
                },
                onCancel: () => {
                    receivedResponse = true;
                    setError('Upload interrupted. Check the record before retrying. Your remaining files are still here.');
                    setUploading(false);
                },
                onFinish: () => {
                    if (!receivedResponse) {
                        setError('The upload could not be confirmed. Check the record before retrying. Your remaining files are still here.');
                        setUploading(false);
                    }
                },
            });
        };
        next(0);
    };

    return (
        <div className="flex flex-col gap-3">
            <FileDropzone onFiles={add} accept={accept} hint={hint} disabled={uploading} />

            {items.length ? (
                <div className="flex flex-col gap-2">
                    {items.map((it) => (
                        <StagedFileCard key={it.id} file={it.file} onRemove={() => remove(it.id)}>
                            {noteField || sensitive || categoryField ? (
                                <div className="flex flex-col gap-2">
                                    {categoryField ? (
                                        <select
                                            value={it.category}
                                            onChange={(e) => patch(it.id, { category: e.target.value })}
                                            aria-label={categoryField.label}
                                            className={cn('h-8 rounded-md border border-border bg-background px-2 text-xs text-foreground focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none', frontline && 'frontline-tap')}
                                        >
                                            <option value="">{categoryField.label}…</option>
                                            {categoryField.options.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : null}
                                    {noteField ? (
                                        <Input aria-label={`Note for ${it.file.name}`} value={it.note} onChange={(e) => patch(it.id, { note: e.target.value })} placeholder="Note (optional)" className={cn('h-8', frontline && 'frontline-tap')} />
                                    ) : null}
                                    {sensitive ? (
                                        <label className="flex items-center gap-2 text-xs text-foreground">
                                            <input
                                                type="checkbox"
                                                checked={it.sensitive}
                                                onChange={(e) => patch(it.id, { sensitive: e.target.checked })}
                                                className="h-3.5 w-3.5 rounded border-border"
                                            />
                                            {sensitive.label}
                                        </label>
                                    ) : null}
                                </div>
                            ) : null}
                        </StagedFileCard>
                    ))}

                    <div className="flex items-center justify-between gap-2">
                        {error ? <span role="alert" className="text-sm text-status-critical">{error}</span> : <span />}
                        <Button className={cn(frontline && 'frontline-tap shrink-0')} type="button" size="sm" onClick={upload} disabled={uploading}>
                            <Upload className="mr-1.5 h-3.5 w-3.5" /> {uploading ? 'Uploading…' : `Upload ${items.length} file${items.length === 1 ? '' : 's'}`}
                        </Button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
