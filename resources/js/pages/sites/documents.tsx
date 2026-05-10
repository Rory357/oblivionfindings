import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Download,
    Filter,
    FolderOpen,
    FolderPlus,
    Grid3X3,
    List,
    Pencil,
    Search,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    SITE_DOCUMENT_CATEGORIES,
    formatSiteDocumentFileSize,
    getSiteDocumentCategory,
    getSiteDocumentFileInfo,
    isSiteDocumentExpired,
    isSiteDocumentExpiringSoon,
    type SiteDocumentRecord,
} from './_document-helpers';

type Site = {
    id: number;
    name: string;
    type: string;
    display_type?: string | null;
};

type DocumentFolder = {
    id?: number | null;
    name: string;
};

type Props = {
    site: Site;
    can_edit: boolean;
    folders?: DocumentFolder[];
    documents: SiteDocumentRecord[];
};

type DocumentForm = {
    file: File | null;
    title: string;
    category: string;
    folder: string;
    version: string;
    effective_date: string;
    expiry_date: string;
    notes: string;
};

type EditDocumentForm = Omit<DocumentForm, 'file'>;

export default function SiteDocuments({
    site,
    can_edit,
    folders = [],
    documents,
}: Props) {
    const { labels } = usePage().props as {
        labels?: Record<string, string>;
    };

    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [showUpload, setShowUpload] = useState(false);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [editingDoc, setEditingDoc] = useState<SiteDocumentRecord | null>(
        null,
    );

    const uploadForm = useForm<DocumentForm>({
        file: null,
        title: '',
        category: '',
        folder: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        notes: '',
    });

    const editForm = useForm<EditDocumentForm>({
        title: '',
        category: '',
        folder: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        notes: '',
    });

    const allFolders = useMemo(() => {
        const folderNames = new Set<string>();
        folders.forEach((folder) => {
            if (folder.name) folderNames.add(folder.name);
        });
        documents.forEach((document) => {
            if (document.folder) folderNames.add(document.folder);
        });

        return Array.from(folderNames).sort();
    }, [documents, folders]);

    const filtered = useMemo(() => {
        const query = search.trim().toLowerCase();

        return documents.filter((document) => {
            const name = (
                document.title ||
                document.original_name ||
                ''
            ).toLowerCase();

            if (query && !name.includes(query)) return false;
            if (categoryFilter && document.category !== categoryFilter) {
                return false;
            }
            if (
                currentFolder !== null &&
                (document.folder || '') !== currentFolder
            ) {
                return false;
            }

            return true;
        });
    }, [categoryFilter, currentFolder, documents, search]);

    const filesInCurrentView = useMemo(() => {
        if (currentFolder !== null) return filtered;

        return filtered.filter((document) => !document.folder);
    }, [currentFolder, filtered]);

    const folderCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        const query = search.trim().toLowerCase();

        allFolders.forEach((folder) => {
            if (categoryFilter) return;
            if (query && !folder.toLowerCase().includes(query)) return;

            counts[folder] = 0;
        });

        documents.forEach((document) => {
            if (!document.folder) return;

            const name = (
                document.title ||
                document.original_name ||
                ''
            ).toLowerCase();

            if (query && !name.includes(query)) return;
            if (categoryFilter && document.category !== categoryFilter) return;

            counts[document.folder] = (counts[document.folder] || 0) + 1;
        });

        return counts;
    }, [allFolders, categoryFilter, documents, search]);

    const stats = {
        total: documents.length,
        folders: allFolders.length,
        expiring: documents.filter((document) =>
            isSiteDocumentExpiringSoon(document.expiry_date),
        ).length,
        expired: documents.filter((document) =>
            isSiteDocumentExpired(document.expiry_date),
        ).length,
    };

    const visibleFolders = useMemo(
        () => Object.entries(folderCounts).sort(([a], [b]) => a.localeCompare(b)),
        [folderCounts],
    );
    const isEmpty = filesInCurrentView.length === 0 && visibleFolders.length === 0;

    const openUpload = (folder: string | null = currentFolder) => {
        uploadForm.setData('folder', folder ?? '');
        setShowUpload(true);
    };

    const openEdit = (document: SiteDocumentRecord) => {
        setEditingDoc(document);
        editForm.setData({
            title: document.title ?? '',
            category: document.category ?? '',
            folder: document.folder ?? '',
            version: document.version ?? '',
            effective_date: document.effective_date ?? '',
            expiry_date: document.expiry_date ?? '',
            notes: document.notes ?? '',
        });
    };

    const createFolder = () => {
        const folder = newFolderName.trim();
        if (!folder) return;

        router.post(
            `/sites/${site.id}/document-folders`,
            { name: folder },
            {
                preserveScroll: true,
                onStart: () => setCreatingFolder(true),
                onSuccess: () => {
                    setCurrentFolder(folder);
                    setShowNewFolder(false);
                    setNewFolderName('');
                },
                onFinish: () => setCreatingFolder(false),
            },
        );
    };

    const deleteDocument = (document: SiteDocumentRecord) => {
        if (!confirm('Delete this document?')) return;

        router.delete(`/sites/${site.id}/documents/${document.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['site.plural'] ?? 'Sites',
                    href: '/sites',
                },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Documents', href: `/sites/${site.id}/documents` },
            ]}
        >
            <Head title={`Documents - ${site.name}`} />

            <div className="space-y-4 p-4 lg:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold">Documents</h1>
                        <p className="text-sm text-muted-foreground">
                            {site.name} document library
                        </p>
                    </div>
                    {can_edit && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                onClick={() => setShowNewFolder(true)}
                            >
                                <FolderPlus className="h-4 w-4" />
                                New Folder
                            </Button>
                            <Button
                                className="gap-1.5 bg-primary hover:bg-primary"
                                onClick={() => openUpload()}
                            >
                                <Upload className="h-4 w-4" />
                                Upload Document
                            </Button>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatTile label="Total" value={stats.total} highlight />
                    <StatTile label="Folders" value={stats.folders} highlight />
                    <StatTile
                        label="Expiring"
                        value={stats.expiring}
                        tone={stats.expiring > 0 ? 'warning' : undefined}
                    />
                    <StatTile
                        label="Expired"
                        value={stats.expired}
                        tone={stats.expired > 0 ? 'critical' : undefined}
                    />
                </div>

                {currentFolder && (
                    <div className="flex items-center gap-2 text-sm">
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            onClick={() => setCurrentFolder(null)}
                            className="h-auto p-0 text-primary"
                        >
                            All Documents
                        </Button>
                        <span className="text-muted-foreground">/</span>
                        <span className="font-medium">{currentFolder}</span>
                    </div>
                )}

                <Card className="flex-row flex-wrap items-center gap-2 rounded-xl bg-card/50 p-3">
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search documents..."
                            className="h-9 pl-8 text-sm"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <Select
                        value={categoryFilter || 'ALL'}
                        onValueChange={(value) =>
                            setCategoryFilter(value === 'ALL' ? '' : value)
                        }
                    >
                        <SelectTrigger className="h-9 w-[170px] text-xs">
                            <Filter className="mr-1 h-3 w-3" />
                            <SelectValue placeholder="All Categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ALL">All Categories</SelectItem>
                            {SITE_DOCUMENT_CATEGORIES.map((category) => (
                                <SelectItem
                                    key={category.value}
                                    value={category.value}
                                >
                                    {category.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex rounded-lg border">
                        <Button
                            type="button"
                            variant={viewMode === 'grid' ? 'default' : 'ghost'}
                            size="sm"
                            className="h-9 rounded-r-none px-2.5"
                            onClick={() => setViewMode('grid')}
                            aria-label="Grid view"
                        >
                            <Grid3X3 className="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant={viewMode === 'list' ? 'default' : 'ghost'}
                            size="sm"
                            className="h-9 rounded-l-none px-2.5"
                            onClick={() => setViewMode('list')}
                            aria-label="List view"
                        >
                            <List className="h-4 w-4" />
                        </Button>
                    </div>
                </Card>

                {isEmpty ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <FolderOpen className="h-8 w-8 text-primary" />
                            </div>
                            <p className="font-medium">No Documents</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {search || categoryFilter
                                    ? 'No documents match your filters.'
                                    : currentFolder
                                      ? 'No documents in this folder yet.'
                                      : `Upload documents for ${site.name}.`}
                            </p>
                            {can_edit && !search && !categoryFilter && (
                                <Button
                                    className="mt-4 gap-1.5 bg-primary hover:bg-primary"
                                    size="sm"
                                    onClick={() => openUpload()}
                                >
                                    <Upload className="h-3.5 w-3.5" />
                                    Upload
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    <div className="space-y-6">
                        {currentFolder === null && visibleFolders.length > 0 && (
                            <div>
                                <div className="mb-2 flex items-center gap-2">
                                    <FolderOpen className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold">
                                        Folders
                                    </span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    {visibleFolders.map(([folder, count]) => (
                                        <FolderTile
                                            key={folder}
                                            folder={folder}
                                            count={count}
                                            onOpen={() =>
                                                setCurrentFolder(folder)
                                            }
                                        />
                                    ))}
                                </div>
                            </div>
                        )}

                        {filesInCurrentView.length > 0 && (
                            <div>
                                <div className="mb-2 flex items-center gap-2">
                                    <FolderOpen className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold">
                                        {currentFolder ?? 'Unfiled Documents'}
                                    </span>
                                    <Badge variant="secondary">
                                        {filesInCurrentView.length}
                                    </Badge>
                                </div>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    {filesInCurrentView.map((document) => (
                                        <DocumentCard
                                            key={document.id}
                                            siteId={site.id}
                                            document={document}
                                            canEdit={can_edit}
                                            onEdit={openEdit}
                                            onDelete={deleteDocument}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {currentFolder === null &&
                            visibleFolders.map(([folder, count]) => (
                                <FolderListRow
                                    key={folder}
                                    folder={folder}
                                    count={count}
                                    onOpen={() => setCurrentFolder(folder)}
                                />
                            ))}
                        {filesInCurrentView.map((document) => (
                            <DocumentListRow
                                key={document.id}
                                siteId={site.id}
                                document={document}
                                canEdit={can_edit}
                                onEdit={openEdit}
                                onDelete={deleteDocument}
                            />
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={showUpload} onOpenChange={setShowUpload}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Upload Document</DialogTitle>
                        <DialogDescription>
                            Add a document to the {site.name} library.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary bg-primary/10 p-8 transition-colors hover:bg-primary/15">
                            <Upload className="mb-2 h-8 w-8 text-primary" />
                            <p className="text-sm font-medium text-primary">
                                {uploadForm.data.file
                                    ? uploadForm.data.file.name
                                    : 'Click to select a file'}
                            </p>
                            <p className="mt-1 text-xs text-primary">
                                PDF, Word, Excel, images, or text up to 50MB
                            </p>
                            <input
                                type="file"
                                className="hidden"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.txt,.rtf"
                                onChange={(event) => {
                                    const file =
                                        event.target.files?.[0] ?? null;
                                    uploadForm.setData('file', file);

                                    if (file && !uploadForm.data.title) {
                                        uploadForm.setData(
                                            'title',
                                            file.name.replace(/\.[^/.]+$/, ''),
                                        );
                                    }
                                }}
                            />
                        </label>

                        <DocumentFormFields
                            data={uploadForm.data}
                            setData={uploadForm.setData}
                            folders={allFolders}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setShowUpload(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="bg-primary hover:bg-primary"
                            disabled={
                                uploadForm.processing || !uploadForm.data.file
                            }
                            onClick={() =>
                                uploadForm.post(
                                    `/sites/${site.id}/documents`,
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            uploadForm.reset();
                                            setShowUpload(false);
                                        },
                                    },
                                )
                            }
                        >
                            Upload
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editingDoc !== null}
                onOpenChange={(open) => !open && setEditingDoc(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Document</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <DocumentFormFields
                            data={editForm.data}
                            setData={editForm.setData}
                            folders={allFolders}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setEditingDoc(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={editForm.processing}
                            onClick={() =>
                                editForm.put(
                                    `/sites/${site.id}/documents/${editingDoc?.id}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setEditingDoc(null),
                                    },
                                )
                            }
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={showNewFolder} onOpenChange={setShowNewFolder}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>New Folder</DialogTitle>
                        <DialogDescription>
                            Enter a name for the new folder.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1.5 py-2">
                        <Label>Folder Name</Label>
                        <Input
                            value={newFolderName}
                            onChange={(event) =>
                                setNewFolderName(event.target.value)
                            }
                            placeholder="e.g. Compliance"
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') createFolder();
                            }}
                            autoFocus
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setShowNewFolder(false);
                                setNewFolderName('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="bg-primary hover:bg-primary"
                            disabled={creatingFolder || !newFolderName.trim()}
                            onClick={createFolder}
                        >
                            Create Folder
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function StatTile({
    label,
    value,
    highlight = false,
    tone,
}: {
    label: string;
    value: number;
    highlight?: boolean;
    tone?: 'warning' | 'critical';
}) {
    const valueClass =
        tone === 'warning'
            ? 'text-status-warning'
            : tone === 'critical'
              ? 'text-status-critical'
              : highlight
                ? 'text-primary'
                : 'text-muted-foreground';

    return (
        <div
            className={`rounded-xl border p-3 text-center ${
                highlight ? 'bg-primary/10' : ''
            }`}
        >
            <div className={`text-xl font-bold ${valueClass}`}>{value}</div>
            <div
                className={`text-[10px] tracking-wider uppercase ${
                    highlight ? 'text-primary' : 'text-muted-foreground'
                }`}
            >
                {label}
            </div>
        </div>
    );
}

function FolderTile({
    folder,
    count,
    onOpen,
}: {
    folder: string;
    count: number;
    onOpen: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- This is a full-card selector, not a command button.
        <button
            type="button"
            onClick={onOpen}
            className="group rounded-xl border bg-card p-4 text-left shadow-sm transition-all hover:border-primary hover:shadow-md"
        >
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
                <FolderOpen className="h-6 w-6 text-primary" />
            </div>
            <p className="truncate text-sm font-medium">{folder}</p>
            <p className="text-xs text-muted-foreground">
                {count} {count === 1 ? 'document' : 'documents'}
            </p>
        </button>
    );
}

function FolderListRow({
    folder,
    count,
    onOpen,
}: {
    folder: string;
    count: number;
    onOpen: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- This row opens a folder and needs the whole surface clickable.
        <button
            type="button"
            onClick={onOpen}
            className="flex w-full items-center gap-3 rounded-xl border bg-card p-3 text-left shadow-sm transition-colors hover:border-primary"
        >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                <FolderOpen className="h-5 w-5 text-primary" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{folder}</p>
                <p className="text-xs text-muted-foreground">
                    {count} {count === 1 ? 'document' : 'documents'}
                </p>
            </div>
        </button>
    );
}

function DocumentCard({
    siteId,
    document,
    canEdit,
    onEdit,
    onDelete,
}: {
    siteId: number;
    document: SiteDocumentRecord;
    canEdit: boolean;
    onEdit: (document: SiteDocumentRecord) => void;
    onDelete: (document: SiteDocumentRecord) => void;
}) {
    const fileInfo = getSiteDocumentFileInfo(document.original_name);
    const Icon = fileInfo.icon;
    const category = getSiteDocumentCategory(document.category);

    return (
        <Card
            className={`group transition-all hover:shadow-md ${
                isSiteDocumentExpired(document.expiry_date)
                    ? 'border-status-critical/40'
                    : ''
            }`}
        >
            <CardContent className="p-4">
                <div className="flex flex-col items-center text-center">
                    <div
                        className={`mb-3 flex h-14 w-14 items-center justify-center rounded-xl ${fileInfo.bg}`}
                    >
                        <Icon className={`h-7 w-7 ${fileInfo.color}`} />
                    </div>
                    <Link
                        href={`/sites/${siteId}/documents/${document.id}/download`}
                        className="line-clamp-2 text-sm font-medium hover:text-primary"
                    >
                        {document.title || document.original_name}
                    </Link>
                    <p className="mt-1 max-w-full truncate text-xs text-muted-foreground">
                        {document.original_name}
                    </p>
                    <div className="mt-2 flex flex-wrap justify-center gap-1.5">
                        {category ? (
                            <Badge
                                variant="secondary"
                                className={`text-[10px] ${category.color}`}
                            >
                                {category.label}
                            </Badge>
                        ) : document.category ? (
                            <Badge variant="secondary" className="text-[10px]">
                                {document.category}
                            </Badge>
                        ) : null}
                        <ExpiryBadge date={document.expiry_date} />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {formatSiteDocumentFileSize(document.size_bytes)}
                    </p>
                    <div className="mt-3 flex items-center gap-1">
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8"
                            aria-label="Download document"
                        >
                            <a
                                href={`/sites/${siteId}/documents/${document.id}/download`}
                            >
                                <Download className="h-4 w-4" />
                            </a>
                        </Button>
                        {canEdit && (
                            <>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8"
                                    onClick={() => onEdit(document)}
                                    aria-label="Edit document"
                                >
                                    <Pencil className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8 text-status-critical hover:text-status-critical"
                                    onClick={() => onDelete(document)}
                                    aria-label="Delete document"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function DocumentListRow({
    siteId,
    document,
    canEdit,
    onEdit,
    onDelete,
}: {
    siteId: number;
    document: SiteDocumentRecord;
    canEdit: boolean;
    onEdit: (document: SiteDocumentRecord) => void;
    onDelete: (document: SiteDocumentRecord) => void;
}) {
    const fileInfo = getSiteDocumentFileInfo(document.original_name);
    const Icon = fileInfo.icon;
    const category = getSiteDocumentCategory(document.category);

    return (
        <Card>
            <CardContent className="flex items-center gap-3 p-3">
                <div
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${fileInfo.bg}`}
                >
                    <Icon className={`h-5 w-5 ${fileInfo.color}`} />
                </div>
                <div className="min-w-0 flex-1">
                    <Link
                        href={`/sites/${siteId}/documents/${document.id}/download`}
                        className="truncate text-sm font-medium hover:text-primary"
                    >
                        {document.title || document.original_name}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                        {document.original_name}
                        {document.folder ? ` / ${document.folder}` : ''}
                        {document.size_bytes
                            ? ` / ${formatSiteDocumentFileSize(document.size_bytes)}`
                            : ''}
                    </p>
                </div>
                <div className="hidden items-center gap-2 sm:flex">
                    {category && (
                        <Badge
                            variant="secondary"
                            className={`text-[10px] ${category.color}`}
                        >
                            {category.label}
                        </Badge>
                    )}
                    <ExpiryBadge date={document.expiry_date} />
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        aria-label="Download document"
                    >
                        <a
                            href={`/sites/${siteId}/documents/${document.id}/download`}
                        >
                            <Download className="h-4 w-4" />
                        </a>
                    </Button>
                    {canEdit && (
                        <>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                                onClick={() => onEdit(document)}
                                aria-label="Edit document"
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-status-critical hover:text-status-critical"
                                onClick={() => onDelete(document)}
                                aria-label="Delete document"
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function ExpiryBadge({ date }: { date?: string | null }) {
    if (!date) return null;

    if (isSiteDocumentExpired(date)) {
        return (
            <Badge
                variant="secondary"
                className="bg-status-critical-bg text-[10px] text-status-critical"
            >
                Expired
            </Badge>
        );
    }

    if (isSiteDocumentExpiringSoon(date)) {
        return (
            <Badge
                variant="secondary"
                className="bg-status-warning-bg text-[10px] text-status-warning"
            >
                Expiring
            </Badge>
        );
    }

    return null;
}

function DocumentFormFields({
    data,
    setData,
    folders,
}: {
    data: EditDocumentForm;
    setData: <K extends keyof EditDocumentForm>(
        key: K,
        value: EditDocumentForm[K],
    ) => void;
    folders: string[];
}) {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label>Title</Label>
                    <Input
                        value={data.title}
                        onChange={(event) =>
                            setData('title', event.target.value)
                        }
                    />
                </div>
                <div className="space-y-1.5">
                    <Label>Folder</Label>
                    <Input
                        list="site-document-folders"
                        value={data.folder}
                        onChange={(event) =>
                            setData('folder', event.target.value)
                        }
                        placeholder="Optional folder name"
                    />
                    {folders.length > 0 && (
                        <datalist id="site-document-folders">
                            {folders.map((folder) => (
                                <option key={folder} value={folder} />
                            ))}
                        </datalist>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label>Category</Label>
                    <Select
                        value={data.category || '__none__'}
                        onValueChange={(value) =>
                            setData(
                                'category',
                                value === '__none__' ? '' : value,
                            )
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">No category</SelectItem>
                            {SITE_DOCUMENT_CATEGORIES.map((category) => (
                                <SelectItem
                                    key={category.value}
                                    value={category.value}
                                >
                                    {category.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label>Version</Label>
                    <Input
                        value={data.version}
                        onChange={(event) =>
                            setData('version', event.target.value)
                        }
                        placeholder="v1.0"
                    />
                </div>
                <div className="space-y-1.5">
                    <Label>Effective Date</Label>
                    <Input
                        type="date"
                        value={data.effective_date}
                        onChange={(event) =>
                            setData('effective_date', event.target.value)
                        }
                    />
                </div>
                <div className="space-y-1.5">
                    <Label>Expiry Date</Label>
                    <Input
                        type="date"
                        value={data.expiry_date}
                        onChange={(event) =>
                            setData('expiry_date', event.target.value)
                        }
                    />
                </div>
            </div>
            <div className="space-y-1.5">
                <Label>Notes</Label>
                <Textarea
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                    className="min-h-[70px]"
                />
            </div>
        </>
    );
}
