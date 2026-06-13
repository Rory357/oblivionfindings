import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Download,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    Filter,
    FolderOpen,
    FolderPlus,
    Grid3X3,
    LayoutList,
    Lock,
    Pencil,
    Search,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Doc {
    id: number;
    title: string | null;
    category: string | null;
    folder: string | null;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    expires_at: string | null;
    signed_by_employee: boolean;
    is_restricted: boolean;
    notes?: string | null;
    created_at: string;
    uploaded_by: { id: number; name: string } | null;
}

interface Props {
    profile: { id: number; name: string };
    documents: Doc[];
    categories: string[];
    can: { manage: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const FILE_ICONS: Record<
    string,
    { icon: typeof File; color: string; bg: string }
> = {
    pdf: {
        icon: FileText,
        color: 'text-status-critical',
        bg: 'bg-status-critical-bg',
    },
    doc: { icon: FileText, color: 'text-status-info', bg: 'bg-status-info-bg' },
    docx: {
        icon: FileText,
        color: 'text-status-info',
        bg: 'bg-status-info-bg',
    },
    xls: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    xlsx: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    csv: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    jpg: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    jpeg: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    png: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    gif: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
};

function getFileInfo(mime?: string, name?: string) {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
    return (
        FILE_ICONS[ext] ?? {
            icon: File,
            color: 'text-primary',
            bg: 'bg-primary/10',
        }
    );
}

const NONE = '__none__';

const CATEGORY_COLORS: Record<string, string> = {
    contract: 'bg-status-info-bg text-status-info',
    letter: 'bg-primary/10 text-primary',
    policy: 'bg-status-success-bg text-status-success',
    certificate: 'bg-status-warning-bg text-status-warning',
    offer: 'bg-status-critical-bg text-status-critical',
    other: 'bg-muted text-foreground',
};

function formatLabel(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function formatDate(v?: string | null) {
    if (!v) return '\u2014';
    const d = new Date(v);
    return isNaN(d.getTime())
        ? v
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}
function formatBytes(b: number) {
    if (b < 1024) return `${b} B`;
    if (b < 1048576) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1048576).toFixed(1)} MB`;
}

function isExpiringSoon(date: string | null) {
    if (!date) return false;
    const d = new Date(date);
    const now = new Date();
    return d > now && d.getTime() - now.getTime() < 30 * 86400000;
}

function isExpired(date: string | null) {
    if (!date) return false;
    return new Date(date) < new Date();
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function StaffDocuments({
    profile,
    documents,
    categories,
    can,
}: Props) {
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [showUpload, setShowUpload] = useState(false);
    const [editingDoc, setEditingDoc] = useState<Doc | null>(null);
    const [deletingDoc, setDeletingDoc] = useState<Doc | null>(null);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'People', href: '/hr/people' },
        { title: profile.name, href: `/hr/people/${profile.id}` },
        { title: 'Documents', href: `/hr/people/${profile.id}/documents` },
    ];

    // Derive unique folder names
    const allFolders = useMemo(() => {
        const set = new Set<string>();
        documents.forEach((d) => {
            if (d.folder) set.add(d.folder);
        });
        return Array.from(set).sort();
    }, [documents]);

    // Filtering
    const filtered = useMemo(() => {
        return documents.filter((d) => {
            if (
                search &&
                !(d.title ?? d.original_name ?? '')
                    .toLowerCase()
                    .includes(search.toLowerCase())
            )
                return false;
            if (categoryFilter && d.category !== categoryFilter) return false;
            if (currentFolder !== null) {
                if ((d.folder || '') !== currentFolder) return false;
            }
            return true;
        });
    }, [documents, search, categoryFilter, currentFolder]);

    // Files in current view (root = unfiled only; folder = filtered)
    const filesInCurrentView = useMemo(() => {
        if (currentFolder !== null) return filtered;
        return filtered.filter((d) => !d.folder);
    }, [filtered, currentFolder]);

    // Folder counts (respects search + category filter)
    const folderCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        documents.forEach((d) => {
            if (d.folder) {
                if (
                    search &&
                    !(d.title ?? d.original_name ?? '')
                        .toLowerCase()
                        .includes(search.toLowerCase())
                )
                    return;
                if (categoryFilter && d.category !== categoryFilter) return;
                counts[d.folder] = (counts[d.folder] || 0) + 1;
            }
        });
        return counts;
    }, [documents, search, categoryFilter]);

    // Stats
    const stats = {
        total: documents.length,
        restricted: documents.filter((d) => d.is_restricted).length,
        expiring: documents.filter((d) => isExpiringSoon(d.expires_at)).length,
        expired: documents.filter((d) => isExpired(d.expires_at)).length,
    };

    // Upload form
    const uploadForm = useForm<{
        file: File | null;
        title: string;
        category: string;
        folder: string;
        expires_at: string;
        is_restricted: boolean;
        notes: string;
    }>({
        file: null,
        title: '',
        category: '',
        folder: '',
        expires_at: '',
        is_restricted: false,
        notes: '',
    });

    const editForm = useForm<{
        title: string;
        category: string;
        folder: string;
        expires_at: string;
        is_restricted: boolean;
        notes: string;
    }>({
        title: '',
        category: '',
        folder: '',
        expires_at: '',
        is_restricted: false,
        notes: '',
    });

    function openEdit(d: Doc) {
        editForm.setData({
            title: d.title || '',
            category: d.category || '',
            folder: d.folder || '',
            expires_at: d.expires_at || '',
            is_restricted: d.is_restricted,
            notes: d.notes || '',
        });
        setEditingDoc(d);
    }

    const handleCreateFolder = () => {
        const trimmed = newFolderName.trim();
        if (!trimmed) return;
        setCurrentFolder(trimmed);
        setShowNewFolder(false);
        setNewFolderName('');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Documents - ${profile.name}`} />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref={`/hr/people/${profile.id}`}
                        title="Documents"
                        description={`${profile.name}'s document library`}
                        actions={
                            can.manage ? (
                                <>
                                    <Button
                                        variant="outline"
                                        className="gap-1.5"
                                        size="sm"
                                        onClick={() => setShowNewFolder(true)}
                                    >
                                        <FolderPlus className="h-4 w-4" />
                                        New Folder
                                    </Button>
                                    <Button
                                        className="gap-1.5 bg-primary hover:bg-primary"
                                        onClick={() => {
                                            uploadForm.setData(
                                                'folder',
                                                currentFolder ?? '',
                                            );
                                            setShowUpload(true);
                                        }}
                                    >
                                        <Upload className="h-4 w-4" />
                                        Upload Document
                                    </Button>
                                </>
                            ) : null
                        }
                    />
                }
            >
                {/* Stats Bar */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-primary/10 p-3 text-center">
                        <div className="text-xl font-bold text-primary">
                            {stats.total}
                        </div>
                        <div className="text-[10px] tracking-wider text-primary uppercase">
                            Total
                        </div>
                    </div>
                    <div className="rounded-xl border bg-primary/10 p-3 text-center">
                        <div className="text-xl font-bold text-muted-foreground">
                            {stats.restricted}
                        </div>
                        <div className="text-[10px] tracking-wider text-primary uppercase">
                            Restricted
                        </div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div
                            className={`text-xl font-bold ${stats.expiring > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                        >
                            {stats.expiring}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Expiring
                        </div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div
                            className={`text-xl font-bold ${stats.expired > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                        >
                            {stats.expired}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Expired
                        </div>
                    </div>
                </div>

                {/* Folder breadcrumb */}
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

                {/* Toolbar */}
                <Card className="flex-row flex-wrap items-center gap-2 rounded-xl bg-card/50 p-3">
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search documents..."
                            className="h-9 pl-8 text-sm"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <Select
                        value={categoryFilter || 'ALL'}
                        onValueChange={(v) =>
                            setCategoryFilter(v === 'ALL' ? '' : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[150px] text-xs">
                            <Filter className="mr-1 h-3 w-3" />
                            <SelectValue placeholder="All Categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ALL">All Categories</SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c} value={c}>
                                    {formatLabel(c)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex rounded-lg border">
                        <Button
                            variant={viewMode === 'grid' ? 'default' : 'ghost'}
                            size="sm"
                            className="h-9 rounded-r-none px-2.5"
                            onClick={() => setViewMode('grid')}
                        >
                            <Grid3X3 className="h-4 w-4" />
                        </Button>
                        <Button
                            variant={viewMode === 'list' ? 'default' : 'ghost'}
                            size="sm"
                            className="h-9 rounded-l-none px-2.5"
                            onClick={() => setViewMode('list')}
                        >
                            <LayoutList className="h-4 w-4" />
                        </Button>
                    </div>
                </Card>

                {/* Documents */}
                {filesInCurrentView.length === 0 &&
                (currentFolder !== null ||
                    Object.keys(folderCounts).length === 0) ? (
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
                                      : `Upload documents for ${profile.name}.`}
                            </p>
                            {can.manage && !search && !categoryFilter && (
                                <Button
                                    className="mt-4 gap-1.5 bg-primary hover:bg-primary"
                                    size="sm"
                                    onClick={() => {
                                        uploadForm.setData(
                                            'folder',
                                            currentFolder ?? '',
                                        );
                                        setShowUpload(true);
                                    }}
                                >
                                    <Upload className="h-3.5 w-3.5" /> Upload
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    /* Grid View */
                    <div className="space-y-6">
                        {/* Folder cards (only at root level) */}
                        {currentFolder === null &&
                            Object.keys(folderCounts).length > 0 && (
                                <div>
                                    <div className="mb-2 flex items-center gap-2">
                                        <FolderOpen className="h-4 w-4 text-primary" />
                                        <span className="text-sm font-semibold">
                                            Folders
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                        {Object.entries(folderCounts)
                                            .sort(([a], [b]) =>
                                                a.localeCompare(b),
                                            )
                                            .map(([folder, count]) => (
                                                /* eslint-disable-next-line no-restricted-syntax -- Folder selectors are custom card-style buttons, not standard action buttons. */
                                                <button
                                                    key={folder}
                                                    type="button"
                                                    aria-label={`Open ${folder} folder`}
                                                    onClick={() =>
                                                        setCurrentFolder(folder)
                                                    }
                                                    className="flex flex-col items-center rounded-xl border bg-card p-4 transition-all hover:-translate-y-0.5 hover:border-primary hover:shadow-md"
                                                >
                                                    <FolderOpen className="h-10 w-10 text-status-warning" />
                                                    <span className="mt-2 text-xs font-medium">
                                                        {folder}
                                                    </span>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {count} file
                                                        {count !== 1 ? 's' : ''}
                                                    </span>
                                                </button>
                                            ))}
                                    </div>
                                </div>
                            )}

                        {/* File cards */}
                        {filesInCurrentView.length > 0 && (
                            <div>
                                {currentFolder === null && (
                                    <div className="mb-2 flex items-center gap-2">
                                        <FileText className="h-4 w-4 text-primary" />
                                        <span className="text-sm font-semibold">
                                            Unfiled Documents
                                        </span>
                                        <Badge
                                            variant="secondary"
                                            className="text-[10px]"
                                        >
                                            {filesInCurrentView.length}
                                        </Badge>
                                    </div>
                                )}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                    {filesInCurrentView.map((d) => {
                                        const fi = getFileInfo(
                                            d.mime_type,
                                            d.original_name,
                                        );
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expires_at);
                                        const expiring = isExpiringSoon(
                                            d.expires_at,
                                        );
                                        return (
                                            <div
                                                key={d.id}
                                                className={`group relative rounded-xl border bg-card p-4 transition-all hover:-translate-y-0.5 hover:shadow-md ${expired ? 'border-status-critical/30' : expiring ? 'border-status-warning/30' : ''}`}
                                            >
                                                {/* File icon */}
                                                <div
                                                    className={`mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-xl ${fi.bg}`}
                                                >
                                                    <IconComp
                                                        className={`h-7 w-7 ${fi.color}`}
                                                    />
                                                </div>
                                                {/* Title */}
                                                <h3 className="line-clamp-2 text-center text-xs leading-tight font-medium">
                                                    {d.title || d.original_name}
                                                </h3>
                                                {/* Meta */}
                                                <div className="mt-2 flex items-center justify-center gap-1">
                                                    {d.is_restricted && (
                                                        <span title="Restricted">
                                                            <Lock className="h-3 w-3 text-muted-foreground" />
                                                        </span>
                                                    )}
                                                    {expired && (
                                                        <Badge className="h-4 border-0 bg-status-critical-bg px-1 text-[8px] text-status-critical">
                                                            Expired
                                                        </Badge>
                                                    )}
                                                    {expiring && !expired && (
                                                        <Badge className="h-4 border-0 bg-status-warning-bg px-1 text-[8px] text-status-warning">
                                                            Expiring
                                                        </Badge>
                                                    )}
                                                    {d.category && (
                                                        <Badge
                                                            variant="outline"
                                                            className={`h-4 border-0 px-1 text-[8px] ${CATEGORY_COLORS[d.category] || 'bg-muted text-muted-foreground'}`}
                                                        >
                                                            {formatLabel(
                                                                d.category,
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                                {/* Hover actions */}
                                                <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 rounded-b-xl bg-gradient-to-t from-white via-white to-transparent pt-6 pb-2 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <a
                                                        href={`/hr/people/${profile.id}/documents/${d.id}/download`}
                                                        className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary hover:bg-primary/20"
                                                    >
                                                        <Download className="h-3.5 w-3.5" />
                                                    </a>
                                                    {can.manage && (
                                                        <>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Edit document"
                                                                onClick={() =>
                                                                    openEdit(d)
                                                                }
                                                                className="h-7 w-7 rounded-full bg-muted text-muted-foreground hover:bg-muted"
                                                            >
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Delete document"
                                                                onClick={() =>
                                                                    setDeletingDoc(
                                                                        d,
                                                                    )
                                                                }
                                                                className="h-7 w-7 rounded-full bg-status-critical-bg text-status-critical hover:bg-status-critical-bg"
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    /* List View */
                    <Card>
                        <CardContent className="p-0">
                            {/* Folder rows at root level */}
                            {currentFolder === null &&
                                Object.keys(folderCounts).length > 0 && (
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {Object.entries(folderCounts)
                                                .sort(([a], [b]) =>
                                                    a.localeCompare(b),
                                                )
                                                .map(([folder, count]) => (
                                                    <tr
                                                        key={folder}
                                                        className="cursor-pointer border-b hover:bg-muted"
                                                        onClick={() =>
                                                            setCurrentFolder(
                                                                folder,
                                                            )
                                                        }
                                                    >
                                                        <td
                                                            className="px-4 py-2.5"
                                                            colSpan={6}
                                                        >
                                                            <div className="flex items-center gap-2.5">
                                                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg">
                                                                    <FolderOpen className="h-4 w-4 text-status-warning" />
                                                                </div>
                                                                <div>
                                                                    <p className="font-medium">
                                                                        {folder}
                                                                    </p>
                                                                    <p className="text-[10px] text-muted-foreground">
                                                                        {count}{' '}
                                                                        file
                                                                        {count !==
                                                                        1
                                                                            ? 's'
                                                                            : ''}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                        </tbody>
                                    </table>
                                )}
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium">
                                            Name
                                        </th>
                                        <th className="hidden px-4 py-2.5 font-medium sm:table-cell">
                                            Folder
                                        </th>
                                        <th className="hidden px-4 py-2.5 font-medium md:table-cell">
                                            Category
                                        </th>
                                        <th className="hidden px-4 py-2.5 font-medium lg:table-cell">
                                            Expires
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Size
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filesInCurrentView.map((d) => {
                                        const fi = getFileInfo(
                                            d.mime_type,
                                            d.original_name,
                                        );
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expires_at);
                                        const expiring = isExpiringSoon(
                                            d.expires_at,
                                        );
                                        return (
                                            <tr
                                                key={d.id}
                                                className="border-b last:border-0 hover:bg-muted"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center gap-2.5">
                                                        <div
                                                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${fi.bg}`}
                                                        >
                                                            <IconComp
                                                                className={`h-4 w-4 ${fi.color}`}
                                                            />
                                                        </div>
                                                        <div>
                                                            <p className="font-medium">
                                                                {d.title ||
                                                                    d.original_name}
                                                            </p>
                                                            {d.notes && (
                                                                <p className="mt-0.5 line-clamp-1 text-[10px] text-muted-foreground">
                                                                    {d.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                        {d.is_restricted && (
                                                            <Lock className="h-3.5 w-3.5 text-muted-foreground" />
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="hidden px-4 py-2.5 text-muted-foreground sm:table-cell">
                                                    {d.folder || '\u2014'}
                                                </td>
                                                <td className="hidden px-4 py-2.5 md:table-cell">
                                                    {d.category ? (
                                                        <Badge
                                                            className={`border-0 text-[10px] capitalize ${CATEGORY_COLORS[d.category] ?? 'bg-muted text-muted-foreground'}`}
                                                        >
                                                            {formatLabel(
                                                                d.category,
                                                            )}
                                                        </Badge>
                                                    ) : (
                                                        '\u2014'
                                                    )}
                                                </td>
                                                <td className="hidden px-4 py-2.5 lg:table-cell">
                                                    {d.expires_at ? (
                                                        <span
                                                            className={
                                                                expired
                                                                    ? 'font-medium text-status-critical'
                                                                    : expiring
                                                                      ? 'font-medium text-status-warning'
                                                                      : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {formatDate(
                                                                d.expires_at,
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            {'\u2014'}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {formatBytes(d.size_bytes)}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <a
                                                            href={`/hr/people/${profile.id}/documents/${d.id}/download`}
                                                            className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-muted"
                                                        >
                                                            <Download className="h-3.5 w-3.5 text-primary" />
                                                        </a>
                                                        {can.manage && (
                                                            <>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label="Edit document"
                                                                    onClick={() =>
                                                                        openEdit(
                                                                            d,
                                                                        )
                                                                    }
                                                                    className="h-7 w-7 rounded-lg hover:bg-muted"
                                                                >
                                                                    <Pencil className="h-3.5 w-3.5 text-muted-foreground" />
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label="Delete document"
                                                                    onClick={() =>
                                                                        setDeletingDoc(
                                                                            d,
                                                                        )
                                                                    }
                                                                    className="h-7 w-7 rounded-lg hover:bg-status-critical-bg"
                                                                >
                                                                    <Trash2 className="h-3.5 w-3.5 text-status-critical" />
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            {/* Upload Dialog */}
            <Dialog
                open={showUpload}
                onOpenChange={(v) => !v && setShowUpload(false)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Upload Document</DialogTitle>
                        <DialogDescription>
                            Add a new document to {profile.name}&apos;s library.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            uploadForm.post(
                                `/hr/people/${profile.id}/documents`,
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        uploadForm.reset();
                                        setShowUpload(false);
                                    },
                                },
                            );
                        }}
                        className="space-y-4"
                    >
                        {/* Drop zone */}
                        <label className="bg-primary/10/50 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary p-8 transition-colors hover:bg-primary/10">
                            <Upload className="mb-2 h-8 w-8 text-primary" />
                            <p className="text-sm font-medium text-primary">
                                {uploadForm.data.file
                                    ? uploadForm.data.file.name
                                    : 'Click to select a file'}
                            </p>
                            <p className="mt-1 text-xs text-primary">
                                PDF, Word, Excel, Images up to 50MB
                            </p>
                            <input
                                type="file"
                                className="hidden"
                                onChange={(e) => {
                                    const f = e.target.files?.[0];
                                    if (f) {
                                        uploadForm.setData('file', f);
                                        if (!uploadForm.data.title)
                                            uploadForm.setData(
                                                'title',
                                                f.name.replace(/\.[^/.]+$/, ''),
                                            );
                                    }
                                }}
                            />
                        </label>
                        {uploadForm.errors.file && (
                            <p className="text-xs text-status-critical">
                                {uploadForm.errors.file}
                            </p>
                        )}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Title</Label>
                                <Input
                                    value={uploadForm.data.title}
                                    onChange={(e) =>
                                        uploadForm.setData(
                                            'title',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Document title"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Folder</Label>
                                {allFolders.length > 0 ? (
                                    <Select
                                        value={uploadForm.data.folder || NONE}
                                        onValueChange={(v) =>
                                            uploadForm.setData(
                                                'folder',
                                                v === NONE ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="No folder" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                No folder
                                            </SelectItem>
                                            {allFolders.map((f) => (
                                                <SelectItem key={f} value={f}>
                                                    {f}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input
                                        value={uploadForm.data.folder}
                                        onChange={(e) =>
                                            uploadForm.setData(
                                                'folder',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Optional folder name"
                                    />
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select
                                    value={uploadForm.data.category || NONE}
                                    onValueChange={(v) =>
                                        uploadForm.setData(
                                            'category',
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            None
                                        </SelectItem>
                                        {categories.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {formatLabel(c)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={uploadForm.data.expires_at}
                                    onChange={(e) =>
                                        uploadForm.setData(
                                            'expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={uploadForm.data.is_restricted}
                                onCheckedChange={(v) =>
                                    uploadForm.setData('is_restricted', !!v)
                                }
                            />
                            <Label className="text-sm">
                                Restricted (hidden from staff member)
                            </Label>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={uploadForm.data.notes}
                                onChange={(e) =>
                                    uploadForm.setData('notes', e.target.value)
                                }
                                className="min-h-[60px]"
                                placeholder="Optional notes about this document"
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
                                type="submit"
                                className="bg-primary hover:bg-primary"
                                disabled={
                                    uploadForm.processing ||
                                    !uploadForm.data.file
                                }
                            >
                                Upload
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit Dialog */}
            <Dialog
                open={!!editingDoc}
                onOpenChange={(v) => !v && setEditingDoc(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Document</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (editingDoc)
                                editForm.put(
                                    `/hr/people/${profile.id}/documents/${editingDoc.id}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setEditingDoc(null),
                                    },
                                );
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Title</Label>
                                <Input
                                    value={editForm.data.title}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'title',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Folder</Label>
                                {allFolders.length > 0 ? (
                                    <Select
                                        value={editForm.data.folder || NONE}
                                        onValueChange={(v) =>
                                            editForm.setData(
                                                'folder',
                                                v === NONE ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="No folder" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                No folder
                                            </SelectItem>
                                            {allFolders.map((f) => (
                                                <SelectItem key={f} value={f}>
                                                    {f}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input
                                        value={editForm.data.folder}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'folder',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Optional folder name"
                                    />
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select
                                    value={editForm.data.category || NONE}
                                    onValueChange={(v) =>
                                        editForm.setData(
                                            'category',
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            None
                                        </SelectItem>
                                        {categories.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {formatLabel(c)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={editForm.data.expires_at}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={editForm.data.is_restricted}
                                onCheckedChange={(v) =>
                                    editForm.setData('is_restricted', !!v)
                                }
                            />
                            <Label className="text-sm">
                                Restricted (hidden from staff member)
                            </Label>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={editForm.data.notes}
                                onChange={(e) =>
                                    editForm.setData('notes', e.target.value)
                                }
                                className="min-h-[60px]"
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
                                type="submit"
                                disabled={editForm.processing}
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation */}
            <Dialog
                open={!!deletingDoc}
                onOpenChange={(v) => !v && setDeletingDoc(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Delete Document</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete &quot;
                            {deletingDoc?.title || deletingDoc?.original_name}
                            &quot;? This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingDoc(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (deletingDoc)
                                    router.delete(
                                        `/hr/people/${profile.id}/documents/${deletingDoc.id}`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setDeletingDoc(null),
                                        },
                                    );
                            }}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* New Folder Dialog */}
            <Dialog open={showNewFolder} onOpenChange={setShowNewFolder}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>New Folder</DialogTitle>
                        <DialogDescription>
                            Enter a name for the new folder.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Folder Name</Label>
                            <Input
                                value={newFolderName}
                                onChange={(e) =>
                                    setNewFolderName(e.target.value)
                                }
                                placeholder="e.g. Employment Records"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') handleCreateFolder();
                                }}
                                autoFocus
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setShowNewFolder(false);
                                setNewFolderName('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-primary hover:bg-primary"
                            disabled={!newFolderName.trim()}
                            onClick={handleCreateFolder}
                        >
                            Create Folder
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
