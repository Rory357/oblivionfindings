import { useForm } from '@inertiajs/react';
import {
    Award,
    BarChart3,
    BookOpen,
    FileStack,
    FolderOpen,
    Landmark,
    ListChecks,
    Loader2,
    ScrollText,
    Upload,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, TilePicker } from '@/components/wizard/primitives';

/** Document types as stored in governance_documents.document_type. */
export const DOCUMENT_TYPE_ICONS: Record<
    string,
    React.ComponentType<{ className?: string }>
> = {
    constitution: Landmark,
    terms_of_reference: ScrollText,
    policy: BookOpen,
    procedure: ListChecks,
    template: FileStack,
    report: BarChart3,
    certificate: Award,
};

const DOCUMENT_TYPE_BLURBS: Record<string, string> = {
    constitution: 'Trust deed, constitution or charter',
    terms_of_reference: 'Committee and board terms',
    policy: 'Board policy documents',
    procedure: 'Operating procedures',
    template: 'Reusable board templates',
    report: 'Reports and papers',
    certificate: 'Registrations and certificates',
};

export function documentTypeIcon(type: string | null | undefined) {
    return (type && DOCUMENT_TYPE_ICONS[type]) || FolderOpen;
}

interface UploadForm {
    title: string;
    category: string;
    description: string;
    file: File | null;
}

export function UploadDocumentDialog({
    open,
    onClose,
    categories,
}: {
    open: boolean;
    onClose: () => void;
    categories: Array<{ value: string; label: string }>;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <UploadDocumentBody
                        onClose={onClose}
                        categories={categories}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function UploadDocumentBody({
    onClose,
    categories,
}: {
    onClose: () => void;
    categories: Array<{ value: string; label: string }>;
}) {
    const form = useForm<UploadForm>({
        title: '',
        category: categories[0]?.value ?? 'policy',
        description: '',
        file: null,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/governance/documents', {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string | null }
                    | undefined;
                if (!flash?.error) onClose();
            },
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Upload className="h-4 w-4 text-primary" />
                    Upload document
                </DialogTitle>
                <DialogDescription>
                    Add a board document to the governance library. Files are
                    stored privately and downloaded through access checks.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4">
                <Field label="Title" required error={form.errors.title}>
                    <Input
                        id="document-title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. Trust Deed (amended 2025)"
                    />
                </Field>
                <Field label="Document type" required error={form.errors.category}>
                    <TilePicker
                        cols={3}
                        value={form.data.category}
                        onChange={(v) => form.setData('category', v)}
                        options={categories.map((c) => ({
                            key: c.value,
                            label: c.label,
                            description: DOCUMENT_TYPE_BLURBS[c.value],
                            icon: documentTypeIcon(c.value),
                        }))}
                    />
                </Field>
                <Field label="Description" error={form.errors.description}>
                    <Textarea
                        id="document-description"
                        rows={3}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="What the document is and when the board relies on it."
                    />
                </Field>
                <Field label="File" required error={form.errors.file}>
                    {form.data.file ? (
                        <StagedFileCard
                            file={form.data.file}
                            onRemove={() => form.setData('file', null)}
                        />
                    ) : (
                        <FileDropzone
                            id="document-file"
                            multiple={false}
                            title="Drag & drop the document here"
                            hint="Up to 20 MB"
                            onFiles={(files) =>
                                form.setData('file', files[0] ?? null)
                            }
                        />
                    )}
                </Field>
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.file ||
                        !form.data.title.trim()
                    }
                >
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <Upload className="h-4 w-4" />
                    )}
                    Upload document
                </Button>
            </DialogFooter>
        </form>
    );
}
