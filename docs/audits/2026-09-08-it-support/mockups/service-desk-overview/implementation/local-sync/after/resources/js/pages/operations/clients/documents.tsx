import { ConfirmDialog } from '@/components/confirm-dialog';
import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
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
import { EmptyState } from '@/components/ui/empty-state';
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
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Download,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    FolderOpen,
    FolderPlus,
    Globe,
    Grid3X3,
    Layers,
    List,
    Pencil,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ---------------------------------------------------------------------------
// File type helpers
// ---------------------------------------------------------------------------

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

function formatFileSize(bytes?: number) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function isExpired(date?: string | null) {
    if (!date) return false;
    return new Date(date) < new Date();
}

function isExpiringSoon(date?: string | null) {
    if (!date) return false;
    const d = new Date(date);
    const now = new Date();
    return d > now && d.getTime() - now.getTime() < 30 * 86400000;
}

const CATEGORIES = [
    {
        value: 'care_plan',
        label: 'Care Plans',
        color: 'bg-primary/10 text-primary',
    },
    {
        value: 'assessment',
        label: 'Assessments',
        color: 'bg-status-info-bg text-status-info',
    },
    {
        value: 'medical',
        label: 'Medical',
        color: 'bg-status-critical-bg text-status-critical',
    },
    {
        value: 'legal',
        label: 'Legal',
        color: 'bg-status-warning-bg text-status-warning',
    },
    {
        value: 'policy',
        label: 'Policies',
        color: 'bg-status-success-bg text-status-success',
    },
    {
        value: 'consent',
        label: 'Consents',
        color: 'bg-primary/10 text-primary',
    },
    { value: 'other', label: 'Other', color: 'bg-muted text-foreground' },
];

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    folders?: Array<{ id?: number | null; name: string }>;
    documents: Array<any>;
};

type ViewKey = 'all' | 'shared' | 'expiring' | 'expired';

export default function ClientDocuments({
    client,
    can_edit,
    folders = [],
    documents,
}: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();

    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [view, setViewState] = useState<ViewKey>('all');
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [showUpload, setShowUpload] = useState(false);
    const [editingDoc, setEditingDoc] = useState<any>(null);
    const [documentToDelete, setDocumentToDelete] = useState<any>(null);
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const [creatingFolder, setCreatingFolder] = useState(false);

    const uploadForm = useForm<{
        file: File | null;
        title: string;
        category: string;
        folder: string;
        version: string;
        effective_date: string;
        expiry_date: string;
        portal_visible: boolean;
        notes: string;
    }>({
        file: null,
        title: '',
        category: '',
        folder: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        portal_visible: false,
        notes: '',
    });

    const editForm = useForm<{
        title: string;
        category: string;
        folder: string;
        version: string;
        effective_date: string;
        expiry_date: string;
        portal_visible: boolean;
        notes: string;
    }>({
        title: '',
        category: '',
        folder: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        portal_visible: false,
        notes: '',
    });

    const allFolders = useMemo(() => {
        const set = new Set<string>();
        folders.forEach((folder) => {
            if (folder.name) set.add(folder.name);
        });
        documents.forEach((d) => {
            if (d.folder) set.add(d.folder);
        });
        return Array.from(set).sort();
    }, [documents, folders]);

    // A rail view is a flat queue across ALL folders; folder browsing only
    // applies on the "All documents" view.
    const setView = (key: ViewKey) => {
        setViewState(key);
        setCurrentFolder(null);
    };

    const filtered = useMemo(() => {
        return documents.filter((d) => {
            if (view === 'shared' && !d.portal_visible) return false;
            if (
                view === 'expiring' &&
                !(isExpiringSoon(d.expiry_date) && !isExpired(d.expiry_date))
            )
                return false;
            if (view === 'expired' && !isExpired(d.expiry_date)) return false;
            if (
                search &&
                !(d.title ?? d.original_name ?? '')
                    .toLowerCase()
                    .includes(search.toLowerCase())
            )
                return false;
            if (categoryFilter && d.category !== categoryFilter) return false;
            // Folder filtering (All documents view only)
            if (view === 'all' && currentFolder !== null) {
                if ((d.folder || '') !== currentFolder) return false;
            }
            return true;
        });
    }, [documents, view, search, categoryFilter, currentFolder]);

    // Documents in the current view (root = no folder selected, shows unfiled + folder cards)
    const filesInCurrentView = useMemo(() => {
        if (view !== 'all') return filtered;
        if (currentFolder !== null) return filtered;
        // At root level, show documents without a folder
        return filtered.filter((d) => !d.folder);
    }, [filtered, view, currentFolder]);

    // Folder counts for root view
    const folderCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        const query = search.trim().toLowerCase();

        allFolders.forEach((folder) => {
            if (categoryFilter) return;
            if (query && !folder.toLowerCase().includes(query)) return;

            counts[folder] = 0;
        });

        documents.forEach((d) => {
            if (d.folder) {
                // Apply search filter to folder counts
                if (
                    query &&
                    !(d.title ?? d.original_name ?? '')
                        .toLowerCase()
                        .includes(query)
                )
                    return;
                if (categoryFilter && d.category !== categoryFilter) return;
                counts[d.folder] = (counts[d.folder] || 0) + 1;
            }
        });
        return counts;
    }, [allFolders, documents, search, categoryFilter]);

    const stats = {
        total: documents.length,
        expiring: documents.filter((d) => isExpiringSoon(d.expiry_date)).length,
        expired: documents.filter((d) => isExpired(d.expiry_date)).length,
        portal: documents.filter((d) => d.portal_visible).length,
    };

    const visibleFolders = useMemo(
        () =>
            Object.entries(folderCounts).sort(([a], [b]) => a.localeCompare(b)),
        [folderCounts],
    );

    const openEdit = (doc: any) => {
        setEditingDoc(doc);
        editForm.setData({
            title: doc.title ?? '',
            category: doc.category ?? '',
            folder: doc.folder ?? '',
            version: doc.version ?? '',
            effective_date: doc.effective_date ?? '',
            expiry_date: doc.expiry_date ?? '',
            portal_visible: !!doc.portal_visible,
            notes: doc.notes ?? '',
        });
    };

    const handleCreateFolder = () => {
        const trimmed = newFolderName.trim();
        if (!trimmed) return;

        router.post(
            `/operations/clients/${client.id}/document-folders`,
            { name: trimmed },
            {
                preserveScroll: true,
                onStart: () => setCreatingFolder(true),
                onSuccess: () => {
                    setCurrentFolder(trimmed);
                    setShowNewFolder(false);
                    setNewFolderName('');
                },
                onFinish: () => setCreatingFolder(false),
            },
        );
    };

    /* ---------------- Event Horizon header ---------------- */

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All documents',
            icon: Layers,
            count: stats.total,
        },
        { key: 'shared', label: 'Shared', icon: Globe, count: stats.portal },
        {
            key: 'expiring',
            label: 'Expiring soon',
            icon: Clock,
            count: stats.expiring,
        },
        {
            key: 'expired',
            label: 'Expired',
            icon: AlertTriangle,
            count: stats.expired,
            alert: true,
        },
    ];
    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All documents';

    const titleChip =
        stats.expired > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {stats.expired} expired
            </PageHeaderStatusChip>
        ) : stats.expiring > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {stats.expiring} expiring soon
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Up to date
            </PageHeaderStatusChip>
        );

    const categoryOptions = [
        { value: 'all', label: 'All categories' },
        ...CATEGORIES.map((c) => ({ value: c.value, label: c.label })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/clients/${client.id}`}
            icon={FolderOpen}
            title={name}
            titleChip={titleChip}
            subline={`Document library · ${stats.total} ${
                stats.total === 1 ? 'document' : 'documents'
            } · ${allFolders.length} ${
                allFolders.length === 1 ? 'folder' : 'folders'
            }`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search documents…"
                    />
                    {can_edit ? (
                        <>
                            <PageHeaderGlassButton
                                icon={FolderPlus}
                                onClick={() => setShowNewFolder(true)}
                            >
                                New folder
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Upload}
                                onClick={() => {
                                    uploadForm.setData(
                                        'folder',
                                        currentFolder ?? '',
                                    );
                                    setShowUpload(true);
                                }}
                            >
                                Upload document
                            </PageHeaderPrimaryButton>
                        </>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Documents"
                        ariaLabel="View all documents"
                        onClick={() => setView('all')}
                    >
                        <PageHeaderMeterBig>{stats.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {allFolders.length}{' '}
                            {allFolders.length === 1 ? 'folder' : 'folders'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {stats.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Shared"
                            ariaLabel="View documents shared to the family portal"
                            onClick={() => setView('shared')}
                        >
                            <PageHeaderMeterDonut
                                percent={(stats.portal / stats.total) * 100}
                                caption={
                                    <>
                                        {stats.portal} of {stats.total}
                                        <br />
                                        in family portal
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Expiring soon"
                        tone={stats.expiring > 0 ? 'warning' : 'success'}
                        ariaLabel="View documents expiring soon"
                        onClick={() => setView('expiring')}
                    >
                        <PageHeaderMeterBig>
                            {stats.expiring}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            within the next 30 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Expired"
                        tone={stats.expired > 0 ? 'critical' : 'success'}
                        ariaLabel="View expired documents"
                        onClick={() => setView('expired')}
                    >
                        <PageHeaderMeterBig>{stats.expired}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their expiry date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={FileText}
                        label="All categories"
                        value={categoryFilter || 'all'}
                        options={categoryOptions}
                        onChange={(v) =>
                            setCategoryFilter(v === 'all' ? '' : v)
                        }
                    />
                    <PageHeaderViewToggle
                        value={viewMode}
                        onChange={setViewMode}
                        ariaLabel="Layout"
                        options={[
                            { value: 'grid', label: 'Grid', icon: Grid3X3 },
                            { value: 'list', label: 'List', icon: List },
                        ]}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Document views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                {
                    title: 'Documents',
                    href: `/operations/clients/${client.id}/documents`,
                },
            ]}
        >
            <Head title={`Documents · ${name}`} />

            <PageLayout hero={header}>
                <ListCaption
                    title={
                        view === 'all' && currentFolder
                            ? currentFolder
                            : currentViewLabel
                    }
                    caption={`${filtered.length} of ${stats.total} ${
                        stats.total === 1 ? 'document' : 'documents'
                    } shown`}
                />

                {/* Breadcrumb */}
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

                {/* Documents */}
                {filesInCurrentView.length === 0 &&
                (view !== 'all' ||
                    currentFolder !== null ||
                    visibleFolders.length === 0) ? (
                    <EmptyState
                        icon={FolderOpen}
                        title="No documents"
                        description={
                            search || categoryFilter || view !== 'all'
                                ? 'No documents match this view or your filters.'
                                : currentFolder
                                  ? 'No documents in this folder yet.'
                                  : `Upload documents for ${client.first_name}.`
                        }
                        action={
                            can_edit &&
                            !search &&
                            !categoryFilter &&
                            view === 'all' ? (
                                <Button
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
                            ) : undefined
                        }
                    />
                ) : viewMode === 'grid' ? (
                    /* Grid View */
                    <div className="space-y-6">
                        {/* Folder cards (only at root level of All documents) */}
                        {view === 'all' &&
                            currentFolder === null &&
                            visibleFolders.length > 0 && (
                                <div>
                                    <div className="mb-2 flex items-center gap-2">
                                        <FolderOpen className="h-4 w-4 text-primary" />
                                        <span className="text-sm font-semibold">
                                            Folders
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                        {visibleFolders.map(
                                            ([folder, count]) => (
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
                                            ),
                                        )}
                                    </div>
                                </div>
                            )}

                        {/* File cards */}
                        {filesInCurrentView.length > 0 && (
                            <div>
                                {view === 'all' && currentFolder === null && (
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
                                    {filesInCurrentView.map((d: any) => {
                                        const fi = getFileInfo(
                                            d.mime_type,
                                            d.original_name,
                                        );
                                        const IconComp = fi.icon;
                                        const expired = isExpired(
                                            d.expiry_date,
                                        );
                                        const expiring = isExpiringSoon(
                                            d.expiry_date,
                                        );
                                        return (
                                            <Card
                                                key={d.id}
                                                className={`group relative gap-0 rounded-xl p-4 transition-all hover:-translate-y-0.5 hover:shadow-md ${expired ? 'border-status-critical/30' : expiring ? 'border-status-warning/30' : ''}`}
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
                                                    {d.portal_visible && (
                                                        <span title="Shared in portal">
                                                            <Globe className="h-3 w-3 text-status-info" />
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
                                                    {d.version && (
                                                        <span className="text-[9px] text-muted-foreground">
                                                            {d.version}
                                                        </span>
                                                    )}
                                                </div>
                                                {/* Hover actions */}
                                                <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 rounded-b-xl bg-gradient-to-t from-card via-card to-transparent pt-6 pb-2 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <a
                                                        href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                                        className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary hover:bg-primary/20"
                                                    >
                                                        <Download className="h-3.5 w-3.5" />
                                                    </a>
                                                    {can_edit && (
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
                                                                    setDocumentToDelete(
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
                                            </Card>
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
                            {/* Folder rows at root level of All documents */}
                            {view === 'all' &&
                                currentFolder === null &&
                                visibleFolders.length > 0 && (
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {visibleFolders.map(
                                                ([folder, count]) => (
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
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                )}
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium">
                                            Name
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Folder
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Category
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Version
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Expiry
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Shared
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filesInCurrentView.map((d: any) => {
                                        const fi = getFileInfo(
                                            d.mime_type,
                                            d.original_name,
                                        );
                                        const IconComp = fi.icon;
                                        const expired = isExpired(
                                            d.expiry_date,
                                        );
                                        const expiring = isExpiringSoon(
                                            d.expiry_date,
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
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {d.folder || '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {d.category && (
                                                        <Badge
                                                            className={`border-0 text-[10px] capitalize ${CATEGORIES.find((c) => c.value === d.category)?.color ?? 'bg-muted text-muted-foreground'}`}
                                                        >
                                                            {d.category}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">
                                                    {d.version || '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {d.expiry_date ? (
                                                        <span
                                                            className={
                                                                expired
                                                                    ? 'font-medium text-status-critical'
                                                                    : expiring
                                                                      ? 'font-medium text-status-warning'
                                                                      : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {new Date(
                                                                d.expiry_date,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                                {
                                                                    day: 'numeric',
                                                                    month: 'short',
                                                                    year: 'numeric',
                                                                },
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {d.portal_visible ? (
                                                        <Globe className="h-4 w-4 text-status-info" />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <a
                                                            href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                                            className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-muted"
                                                        >
                                                            <Download className="h-3.5 w-3.5 text-primary" />
                                                        </a>
                                                        {can_edit && (
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
                                                                        setDocumentToDelete(
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
            <Dialog open={showUpload} onOpenChange={setShowUpload}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Upload Document</DialogTitle>
                        <DialogDescription>
                            Add a new document to {client.first_name}&apos;s
                            library.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        {/* Drop zone */}
                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary bg-primary/5 p-8 transition-colors hover:bg-primary/10">
                            <Upload className="mb-2 h-8 w-8 text-primary" />
                            <p className="text-sm font-medium text-primary">
                                {uploadForm.data.file
                                    ? uploadForm.data.file.name
                                    : 'Click to select a file'}
                            </p>
                            <p className="mt-1 text-xs text-primary">
                                PDF, Word, Excel, Images up to 10MB
                            </p>
                            <input
                                type="file"
                                className="hidden"
                                onChange={(e) => {
                                    const f = e.target.files?.[0] ?? null;
                                    uploadForm.setData('file', f);
                                    if (f && !uploadForm.data.title)
                                        uploadForm.setData(
                                            'title',
                                            f.name.replace(/\.[^/.]+$/, ''),
                                        );
                                }}
                            />
                        </label>
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
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Folder</Label>
                                {allFolders.length > 0 ? (
                                    <Select
                                        value={
                                            uploadForm.data.folder || '__none__'
                                        }
                                        onValueChange={(v) =>
                                            uploadForm.setData(
                                                'folder',
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="No folder" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
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
                                    value={uploadForm.data.category}
                                    onValueChange={(v) =>
                                        uploadForm.setData('category', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map((c) => (
                                            <SelectItem
                                                key={c.value}
                                                value={c.value}
                                            >
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Version</Label>
                                <Input
                                    value={uploadForm.data.version}
                                    onChange={(e) =>
                                        uploadForm.setData(
                                            'version',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="v1.0"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={uploadForm.data.expiry_date}
                                    onChange={(e) =>
                                        uploadForm.setData(
                                            'expiry_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={uploadForm.data.portal_visible}
                                onCheckedChange={(v) =>
                                    uploadForm.setData('portal_visible', !!v)
                                }
                            />
                            <Label className="text-sm">
                                Share with family portal
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
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowUpload(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-primary hover:bg-primary"
                            disabled={
                                uploadForm.processing || !uploadForm.data.file
                            }
                            onClick={() =>
                                uploadForm.post(
                                    `/operations/clients/${client.id}/documents`,
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

            {/* Edit Dialog */}
            <Dialog
                open={!!editingDoc}
                onOpenChange={(open) => !open && setEditingDoc(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Document</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
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
                                        value={
                                            editForm.data.folder || '__none__'
                                        }
                                        onValueChange={(v) =>
                                            editForm.setData(
                                                'folder',
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="No folder" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
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
                                    value={editForm.data.category}
                                    onValueChange={(v) =>
                                        editForm.setData('category', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map((c) => (
                                            <SelectItem
                                                key={c.value}
                                                value={c.value}
                                            >
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Version</Label>
                                <Input
                                    value={editForm.data.version}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'version',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={editForm.data.expiry_date}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'expiry_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={editForm.data.portal_visible}
                                onCheckedChange={(v) =>
                                    editForm.setData('portal_visible', !!v)
                                }
                            />
                            <Label className="text-sm">
                                Share with family portal
                            </Label>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={editForm.data.notes}
                                onChange={(e) =>
                                    editForm.setData('notes', e.target.value)
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setEditingDoc(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={editForm.processing}
                            onClick={() =>
                                editForm.put(
                                    `/operations/clients/${client.id}/documents/${editingDoc?.id}`,
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
                                placeholder="e.g. Medical Records"
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
                            disabled={creatingFolder || !newFolderName.trim()}
                            onClick={handleCreateFolder}
                        >
                            Create Folder
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={documentToDelete !== null}
                onClose={() => setDocumentToDelete(null)}
                onConfirm={() => {
                    if (documentToDelete) {
                        uploadForm.delete(
                            `/operations/clients/${client.id}/documents/${documentToDelete.id}`,
                            { preserveScroll: true },
                        );
                    }
                }}
                title="Delete document?"
                description={`Permanently delete “${documentToDelete?.title ?? documentToDelete?.name ?? 'this document'}”? This action cannot be undone.`}
                confirmText="Delete document"
            />
        </AppLayout>
    );
}
