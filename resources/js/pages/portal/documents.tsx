import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import {
    Download,
    File,
    FileImage,
    FileText,
    Folder,
    FolderOpen,
    Inbox,
    Upload,
    X,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

type Document = {
    id: number;
    title: string;
    category?: string | null;
    notes?: string | null;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    version?: string | null;
    created_at: string;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    documents: Document[];
};

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024)
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function groupByCategory(documents: Document[]): Map<string, Document[]> {
    const grouped = new Map<string, Document[]>();
    for (const doc of documents) {
        const category = doc.category?.trim() || 'Other';
        const existing = grouped.get(category);
        if (existing) {
            existing.push(doc);
        } else {
            grouped.set(category, [doc]);
        }
    }
    return grouped;
}

export default function Documents({ client, documents }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const grouped = groupByCategory(documents);
    const [uploadOpen, setUploadOpen] = useState(false);
    const [dragActive, setDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<{ file: File | null; title: string; notes: string }>({
        file: null,
        title: '',
        notes: '',
    });

    const setFile = useCallback(
        (f: File | null) => {
            form.setData('file', f);
            if (f && !form.data.title) {
                form.setData('title', f.name.replace(/\.[^.]+$/, ''));
            }
        },
        [form],
    );

    const handleDrag = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === 'dragenter' || e.type === 'dragover')
            setDragActive(true);
        else if (e.type === 'dragleave') setDragActive(false);
    }, []);

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            e.stopPropagation();
            setDragActive(false);
            if (e.dataTransfer.files?.[0]) setFile(e.dataTransfer.files[0]);
        },
        [setFile],
    );

    const submitUpload = (e: React.FormEvent) => {
        e.preventDefault();
        if (!form.data.file) return;
        form.post(`/portal/clients/${client.id}/documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setUploadOpen(false);
                form.reset();
                toast.success('Document uploaded successfully.');
            },
            onError: () => toast.error('Please check the form and try again.'),
        });
    };

    const fileIcon = (mime: string) => {
        if (mime.startsWith('image/'))
            return <FileImage className="h-5 w-5 text-primary" />;
        if (mime.includes('pdf'))
            return <FileText className="h-5 w-5 text-status-critical" />;
        return <File className="h-5 w-5 text-status-info" />;
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Documents',
                    href: `/portal/clients/${client.id}/documents`,
                },
            ]}
        >
            <Head title={`${clientName} - Documents`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={Folder}
                        title="Documents"
                        description={`Documents shared with ${client.first_name}'s care team.`}
                        stats={[
                            { label: 'Total', value: documents.length },
                            { label: 'Categories', value: grouped.size },
                        ]}
                        actions={
                            <Dialog
                                open={uploadOpen}
                                onOpenChange={setUploadOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button className="gap-2">
                                        <Upload className="h-4 w-4" />
                                        Upload Document
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-lg">
                                    <DialogHeader>
                                        <DialogTitle>
                                            Upload a Document
                                        </DialogTitle>
                                        <DialogDescription>
                                            Share a document with the care team
                                            — e.g. consent forms, letters, or
                                            reports.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form
                                        onSubmit={submitUpload}
                                        className="space-y-4"
                                    >
                                        {/* Drag & drop zone */}
                                        {!form.data.file ? (
                                            <div
                                                onDragEnter={handleDrag}
                                                onDragOver={handleDrag}
                                                onDragLeave={handleDrag}
                                                onDrop={handleDrop}
                                                onClick={() =>
                                                    fileInputRef.current?.click()
                                                }
                                                className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors ${
                                                    dragActive
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-muted-foreground/25 hover:border-primary/50 hover:bg-muted/50'
                                                }`}
                                            >
                                                <div
                                                    className={`mb-3 flex h-12 w-12 items-center justify-center rounded-full ${dragActive ? 'bg-primary/10' : 'bg-muted'}`}
                                                >
                                                    <Upload
                                                        className={`h-6 w-6 ${dragActive ? 'text-primary' : 'text-muted-foreground'}`}
                                                    />
                                                </div>
                                                <p className="text-sm font-medium">
                                                    {dragActive
                                                        ? 'Drop your file here'
                                                        : 'Drag & drop your file here'}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    or{' '}
                                                    <span className="text-primary underline">
                                                        browse
                                                    </span>{' '}
                                                    to choose
                                                </p>
                                                <p className="mt-2 text-[11px] text-muted-foreground/60">
                                                    PDF, Word, images, or text
                                                    files up to 20 MB
                                                </p>
                                                <input
                                                    ref={fileInputRef}
                                                    type="file"
                                                    className="hidden"
                                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.heic,.txt,.rtf,.odt"
                                                    onChange={(e) => {
                                                        if (e.target.files?.[0])
                                                            setFile(
                                                                e.target
                                                                    .files[0],
                                                            );
                                                    }}
                                                />
                                            </div>
                                        ) : (
                                            <Card className="gap-0 bg-muted/30 py-0 shadow-none">
                                                <CardContent className="flex items-center gap-3 p-4">
                                                    {/* eslint-disable-next-line no-restricted-syntax -- Compact decorative file-type icon well, not a content Card. */}
                                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border bg-background">
                                                        {fileIcon(
                                                            form.data.file.type,
                                                        )}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-medium">
                                                            {
                                                                form.data.file
                                                                    .name
                                                            }
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {formatFileSize(
                                                                form.data.file
                                                                    .size,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-status-critical"
                                                        onClick={() => {
                                                            form.setData(
                                                                'file',
                                                                null,
                                                            );
                                                            if (
                                                                fileInputRef.current
                                                            )
                                                                fileInputRef.current.value =
                                                                    '';
                                                        }}
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                </CardContent>
                                            </Card>
                                        )}
                                        {form.errors.file && (
                                            <p className="text-xs text-status-critical">
                                                {form.errors.file}
                                            </p>
                                        )}

                                        <div>
                                            <Label htmlFor="doc-title">
                                                Title *
                                            </Label>
                                            <Input
                                                id="doc-title"
                                                value={form.data.title}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'title',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. GP Letter - April 2026"
                                            />
                                            {form.errors.title && (
                                                <p className="mt-1 text-xs text-status-critical">
                                                    {form.errors.title}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <Label htmlFor="doc-notes">
                                                Notes
                                            </Label>
                                            <textarea
                                                id="doc-notes"
                                                className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                rows={2}
                                                placeholder="Optional notes for the care team..."
                                                value={form.data.notes}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="flex justify-end gap-2 pt-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => {
                                                    setUploadOpen(false);
                                                    form.reset();
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    form.processing ||
                                                    !form.data.file ||
                                                    !form.data.title
                                                }
                                                className="gap-2"
                                            >
                                                <Upload className="h-4 w-4" />
                                                {form.processing
                                                    ? 'Uploading...'
                                                    : 'Upload'}
                                            </Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        }
                    />
                }
            >
                {documents.length > 0 ? (
                    Array.from(grouped.entries()).map(([category, docs]) => (
                        <Card key={category}>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <FolderOpen className="h-4 w-4 text-primary" />
                                    {category}
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto text-xs"
                                    >
                                        {docs.length}
                                    </Badge>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {docs.map((doc) => (
                                        <div
                                            key={doc.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                                    <FileText className="h-5 w-5 text-primary" />
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium">
                                                        {doc.title}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {doc.original_name}
                                                    </p>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                                        <span>
                                                            {formatFileSize(
                                                                doc.size_bytes,
                                                            )}
                                                        </span>
                                                        <span>&middot;</span>
                                                        <span>
                                                            {formatDate(
                                                                doc.created_at,
                                                            )}
                                                        </span>
                                                        {doc.version &&
                                                            doc.version !==
                                                                '1' && (
                                                                <>
                                                                    <span>
                                                                        &middot;
                                                                    </span>
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="px-1.5 py-0 text-[10px]"
                                                                    >
                                                                        v
                                                                        {
                                                                            doc.version
                                                                        }
                                                                    </Badge>
                                                                </>
                                                            )}
                                                    </div>
                                                    {doc.notes && (
                                                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground italic">
                                                            {doc.notes}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="shrink-0 gap-1.5"
                                                asChild
                                            >
                                                <a
                                                    href={`/portal/clients/${client.id}/documents/${doc.id}/download`}
                                                    download
                                                >
                                                    <Download className="h-3.5 w-3.5" />
                                                    <span className="hidden sm:inline">
                                                        Download
                                                    </span>
                                                </a>
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    ))
                ) : (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                            <Inbox className="mb-3 h-10 w-10 text-muted-foreground/40" />
                            <p className="text-sm font-medium text-muted-foreground">
                                No documents have been shared yet
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground/70">
                                Documents shared by the care team will appear
                                here.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
