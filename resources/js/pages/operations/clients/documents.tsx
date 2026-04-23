import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    Download,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    Filter,
    FolderOpen,
    FolderPlus,
    Globe,
    Grid3X3,
    List,
    Pencil,
    Search,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ---------------------------------------------------------------------------
// File type helpers
// ---------------------------------------------------------------------------

const FILE_ICONS: Record<string, { icon: typeof File; color: string; bg: string }> = {
    pdf: { icon: FileText, color: 'text-red-600', bg: 'bg-red-100' },
    doc: { icon: FileText, color: 'text-blue-600', bg: 'bg-blue-100' },
    docx: { icon: FileText, color: 'text-blue-600', bg: 'bg-blue-100' },
    xls: { icon: FileSpreadsheet, color: 'text-emerald-600', bg: 'bg-emerald-100' },
    xlsx: { icon: FileSpreadsheet, color: 'text-emerald-600', bg: 'bg-emerald-100' },
    csv: { icon: FileSpreadsheet, color: 'text-emerald-600', bg: 'bg-emerald-100' },
    jpg: { icon: FileImage, color: 'text-amber-600', bg: 'bg-amber-100' },
    jpeg: { icon: FileImage, color: 'text-amber-600', bg: 'bg-amber-100' },
    png: { icon: FileImage, color: 'text-amber-600', bg: 'bg-amber-100' },
    gif: { icon: FileImage, color: 'text-amber-600', bg: 'bg-amber-100' },
};

function getFileInfo(mime?: string, name?: string) {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
    return FILE_ICONS[ext] ?? { icon: File, color: 'text-primary', bg: 'bg-primary/10' };
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
    { value: 'care_plan', label: 'Care Plans', color: 'bg-primary/10 text-primary' },
    { value: 'assessment', label: 'Assessments', color: 'bg-blue-100 text-blue-700' },
    { value: 'medical', label: 'Medical', color: 'bg-red-100 text-red-700' },
    { value: 'legal', label: 'Legal', color: 'bg-amber-100 text-amber-700' },
    { value: 'policy', label: 'Policies', color: 'bg-emerald-100 text-emerald-700' },
    { value: 'consent', label: 'Consents', color: 'bg-primary/10 text-primary' },
    { value: 'other', label: 'Other', color: 'bg-muted text-foreground' },
];

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    documents: Array<any>;
};

export default function ClientDocuments({ client, can_edit, documents }: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();

    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [showUpload, setShowUpload] = useState(false);
    const [editingDoc, setEditingDoc] = useState<any>(null);
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [showNewFolder, setShowNewFolder] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');

    const uploadForm = useForm<{ file: File | null; title: string; category: string; folder: string; version: string; effective_date: string; expiry_date: string; portal_visible: boolean; notes: string }>({
        file: null, title: '', category: '', folder: '', version: '', effective_date: '', expiry_date: '', portal_visible: false, notes: '',
    });

    const editForm = useForm<{ title: string; category: string; folder: string; version: string; effective_date: string; expiry_date: string; portal_visible: boolean; notes: string }>({
        title: '', category: '', folder: '', version: '', effective_date: '', expiry_date: '', portal_visible: false, notes: '',
    });

    // Derive unique folder names from all documents
    const allFolders = useMemo(() => {
        const set = new Set<string>();
        documents.forEach(d => { if (d.folder) set.add(d.folder); });
        return Array.from(set).sort();
    }, [documents]);

    const filtered = useMemo(() => {
        return documents.filter(d => {
            if (search && !(d.title ?? d.original_name ?? '').toLowerCase().includes(search.toLowerCase())) return false;
            if (categoryFilter && d.category !== categoryFilter) return false;
            // Folder filtering
            if (currentFolder !== null) {
                if ((d.folder || '') !== currentFolder) return false;
            }
            return true;
        });
    }, [documents, search, categoryFilter, currentFolder]);

    // Documents in the current view (root = no folder selected, shows unfiled + folder cards)
    const filesInCurrentView = useMemo(() => {
        if (currentFolder !== null) return filtered;
        // At root level, show documents without a folder
        return filtered.filter(d => !d.folder);
    }, [filtered, currentFolder]);

    // Folder counts for root view
    const folderCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        documents.forEach(d => {
            if (d.folder) {
                // Apply search filter to folder counts
                if (search && !(d.title ?? d.original_name ?? '').toLowerCase().includes(search.toLowerCase())) return;
                if (categoryFilter && d.category !== categoryFilter) return;
                counts[d.folder] = (counts[d.folder] || 0) + 1;
            }
        });
        return counts;
    }, [documents, search, categoryFilter]);

    const stats = {
        total: documents.length,
        expiring: documents.filter(d => isExpiringSoon(d.expiry_date)).length,
        expired: documents.filter(d => isExpired(d.expiry_date)).length,
        portal: documents.filter(d => d.portal_visible).length,
    };

    const openEdit = (doc: any) => {
        setEditingDoc(doc);
        editForm.setData({
            title: doc.title ?? '', category: doc.category ?? '', folder: doc.folder ?? '', version: doc.version ?? '',
            effective_date: doc.effective_date ?? '', expiry_date: doc.expiry_date ?? '',
            portal_visible: !!doc.portal_visible, notes: doc.notes ?? '',
        });
    };

    const handleCreateFolder = () => {
        const trimmed = newFolderName.trim();
        if (!trimmed) return;
        setCurrentFolder(trimmed);
        setShowNewFolder(false);
        setNewFolderName('');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
                { title: name, href: `/operations/clients/${client.id}` },
                { title: 'Documents', href: `/operations/clients/${client.id}/documents` },
            ]}
        >
            <Head title={`Documents - ${name}`} />

            <div className="space-y-4 p-4 lg:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold">Documents</h1>
                        <p className="text-sm text-muted-foreground">{name}&apos;s document library</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {can_edit && (
                            <>
                                <Button variant="outline" className="gap-1.5" size="sm" onClick={() => setShowNewFolder(true)}>
                                    <FolderPlus className="h-4 w-4" />
                                    New Folder
                                </Button>
                                <Button className="gap-1.5 bg-primary hover:bg-primary" onClick={() => {
                                    uploadForm.setData('folder', currentFolder ?? '');
                                    setShowUpload(true);
                                }}>
                                    <Upload className="h-4 w-4" />
                                    Upload Document
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Stats Bar */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                        <div className="text-xl font-bold text-primary">{stats.total}</div>
                        <div className="text-[10px] uppercase tracking-wider text-primary">Total</div>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                        <div className="text-xl font-bold text-blue-600">{stats.portal}</div>
                        <div className="text-[10px] uppercase tracking-wider text-primary">Shared</div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className={`text-xl font-bold ${stats.expiring > 0 ? 'text-amber-600' : 'text-muted-foreground'}`}>{stats.expiring}</div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Expiring</div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className={`text-xl font-bold ${stats.expired > 0 ? 'text-red-600' : 'text-muted-foreground'}`}>{stats.expired}</div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Expired</div>
                    </div>
                </div>

                {/* Breadcrumb */}
                {currentFolder && (
                    <div className="flex items-center gap-2 text-sm">
                        <button onClick={() => setCurrentFolder(null)} className="text-primary hover:underline">All Documents</button>
                        <span className="text-muted-foreground">/</span>
                        <span className="font-medium">{currentFolder}</span>
                    </div>
                )}

                {/* Toolbar */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-white/50 p-3 shadow-sm">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search documents..." className="h-9 pl-8 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                    <Select value={categoryFilter || 'ALL'} onValueChange={(v) => setCategoryFilter(v === 'ALL' ? '' : v)}>
                        <SelectTrigger className="h-9 w-[150px] text-xs">
                            <Filter className="mr-1 h-3 w-3" />
                            <SelectValue placeholder="All Categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ALL">All Categories</SelectItem>
                            {CATEGORIES.map(c => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="flex rounded-lg border">
                        <Button variant={viewMode === 'grid' ? 'default' : 'ghost'} size="sm" className="h-9 rounded-r-none px-2.5" onClick={() => setViewMode('grid')}>
                            <Grid3X3 className="h-4 w-4" />
                        </Button>
                        <Button variant={viewMode === 'list' ? 'default' : 'ghost'} size="sm" className="h-9 rounded-l-none px-2.5" onClick={() => setViewMode('list')}>
                            <List className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {/* Documents */}
                {filesInCurrentView.length === 0 && (currentFolder !== null || Object.keys(folderCounts).length === 0) ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <FolderOpen className="h-8 w-8 text-primary" />
                            </div>
                            <p className="font-medium">No Documents</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {search || categoryFilter ? 'No documents match your filters.' : currentFolder ? `No documents in this folder yet.` : `Upload documents for ${client.first_name}.`}
                            </p>
                            {can_edit && !search && !categoryFilter && (
                                <Button className="mt-4 gap-1.5 bg-primary hover:bg-primary" size="sm" onClick={() => {
                                    uploadForm.setData('folder', currentFolder ?? '');
                                    setShowUpload(true);
                                }}>
                                    <Upload className="h-3.5 w-3.5" /> Upload
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    /* Grid View */
                    <div className="space-y-6">
                        {/* Folder cards (only at root level) */}
                        {currentFolder === null && Object.keys(folderCounts).length > 0 && (
                            <div>
                                <div className="mb-2 flex items-center gap-2">
                                    <FolderOpen className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold">Folders</span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    {Object.entries(folderCounts).sort(([a], [b]) => a.localeCompare(b)).map(([folder, count]) => (
                                        <button key={folder} onClick={() => setCurrentFolder(folder)}
                                            className="flex flex-col items-center rounded-xl border bg-white p-4 transition-all hover:shadow-md hover:-translate-y-0.5 hover:border-primary">
                                            <FolderOpen className="h-10 w-10 text-amber-500" />
                                            <span className="mt-2 text-xs font-medium">{folder}</span>
                                            <span className="text-[10px] text-muted-foreground">{count} file{count !== 1 ? 's' : ''}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* File cards */}
                        {filesInCurrentView.length > 0 && (
                            <div>
                                {currentFolder === null && <div className="mb-2 flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold">Unfiled Documents</span>
                                    <Badge variant="secondary" className="text-[10px]">{filesInCurrentView.length}</Badge>
                                </div>}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                    {filesInCurrentView.map((d: any) => {
                                        const fi = getFileInfo(d.mime_type, d.original_name);
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expiry_date);
                                        const expiring = isExpiringSoon(d.expiry_date);
                                        return (
                                            <div key={d.id} className={`group relative rounded-xl border bg-white p-4 transition-all hover:shadow-md hover:-translate-y-0.5 ${expired ? 'border-red-200' : expiring ? 'border-amber-200' : ''}`}>
                                                {/* File icon */}
                                                <div className={`mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-xl ${fi.bg}`}>
                                                    <IconComp className={`h-7 w-7 ${fi.color}`} />
                                                </div>
                                                {/* Title */}
                                                <h3 className="text-center text-xs font-medium leading-tight line-clamp-2">{d.title || d.original_name}</h3>
                                                {/* Meta */}
                                                <div className="mt-2 flex items-center justify-center gap-1">
                                                    {d.portal_visible && <span title="Shared in portal"><Globe className="h-3 w-3 text-blue-500" /></span>}
                                                    {expired && <Badge className="h-4 border-0 bg-red-100 px-1 text-[8px] text-red-600">Expired</Badge>}
                                                    {expiring && !expired && <Badge className="h-4 border-0 bg-amber-100 px-1 text-[8px] text-amber-600">Expiring</Badge>}
                                                    {d.version && <span className="text-[9px] text-muted-foreground">{d.version}</span>}
                                                </div>
                                                {/* Hover actions */}
                                                <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 rounded-b-xl bg-gradient-to-t from-white via-white to-transparent pb-2 pt-6 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <a href={`/operations/clients/${client.id}/documents/${d.id}/download`} className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary hover:bg-primary/20">
                                                        <Download className="h-3.5 w-3.5" />
                                                    </a>
                                                    {can_edit && (
                                                        <>
                                                            <button onClick={() => openEdit(d)} className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted">
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </button>
                                                            <button onClick={() => { if (confirm('Delete this document?')) uploadForm.delete(`/operations/clients/${client.id}/documents/${d.id}`, { preserveScroll: true }); }} className="flex h-7 w-7 items-center justify-center rounded-full bg-red-100 text-red-600 hover:bg-red-200">
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </button>
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
                            {currentFolder === null && Object.keys(folderCounts).length > 0 && (
                                <table className="w-full text-sm">
                                    <tbody>
                                        {Object.entries(folderCounts).sort(([a], [b]) => a.localeCompare(b)).map(([folder, count]) => (
                                            <tr key={folder} className="border-b hover:bg-muted cursor-pointer" onClick={() => setCurrentFolder(folder)}>
                                                <td className="px-4 py-2.5" colSpan={6}>
                                                    <div className="flex items-center gap-2.5">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100">
                                                            <FolderOpen className="h-4 w-4 text-amber-600" />
                                                        </div>
                                                        <div>
                                                            <p className="font-medium">{folder}</p>
                                                            <p className="text-[10px] text-muted-foreground">{count} file{count !== 1 ? 's' : ''}</p>
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
                                        <th className="px-4 py-2.5 font-medium">Name</th>
                                        <th className="px-4 py-2.5 font-medium">Folder</th>
                                        <th className="px-4 py-2.5 font-medium">Category</th>
                                        <th className="px-4 py-2.5 font-medium">Version</th>
                                        <th className="px-4 py-2.5 font-medium">Expiry</th>
                                        <th className="px-4 py-2.5 font-medium">Shared</th>
                                        <th className="px-4 py-2.5 font-medium text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filesInCurrentView.map((d: any) => {
                                        const fi = getFileInfo(d.mime_type, d.original_name);
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expiry_date);
                                        const expiring = isExpiringSoon(d.expiry_date);
                                        return (
                                            <tr key={d.id} className="border-b last:border-0 hover:bg-muted">
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center gap-2.5">
                                                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${fi.bg}`}>
                                                            <IconComp className={`h-4 w-4 ${fi.color}`} />
                                                        </div>
                                                        <div>
                                                            <p className="font-medium">{d.title || d.original_name}</p>
                                                            {d.notes && <p className="mt-0.5 text-[10px] text-muted-foreground line-clamp-1">{d.notes}</p>}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">{d.folder || '—'}</td>
                                                <td className="px-4 py-2.5">
                                                    {d.category && <Badge className={`border-0 text-[10px] capitalize ${CATEGORIES.find(c => c.value === d.category)?.color ?? 'bg-muted text-muted-foreground'}`}>{d.category}</Badge>}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">{d.version || '—'}</td>
                                                <td className="px-4 py-2.5">
                                                    {d.expiry_date ? (
                                                        <span className={expired ? 'font-medium text-red-600' : expiring ? 'font-medium text-amber-600' : 'text-muted-foreground'}>
                                                            {new Date(d.expiry_date).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}
                                                        </span>
                                                    ) : <span className="text-muted-foreground">—</span>}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {d.portal_visible ? <Globe className="h-4 w-4 text-blue-500" /> : <span className="text-muted-foreground">—</span>}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <a href={`/operations/clients/${client.id}/documents/${d.id}/download`} className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-muted">
                                                            <Download className="h-3.5 w-3.5 text-primary" />
                                                        </a>
                                                        {can_edit && (
                                                            <>
                                                                <button onClick={() => openEdit(d)} className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-muted">
                                                                    <Pencil className="h-3.5 w-3.5 text-muted-foreground" />
                                                                </button>
                                                                <button onClick={() => { if (confirm('Delete?')) uploadForm.delete(`/operations/clients/${client.id}/documents/${d.id}`, { preserveScroll: true }); }} className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-red-50">
                                                                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                                                                </button>
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
            </div>

            {/* Upload Dialog */}
            <Dialog open={showUpload} onOpenChange={setShowUpload}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Upload Document</DialogTitle>
                        <DialogDescription>Add a new document to {client.first_name}&apos;s library.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        {/* Drop zone */}
                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-primary bg-primary/10/50 p-8 transition-colors hover:bg-primary/10">
                            <Upload className="mb-2 h-8 w-8 text-primary" />
                            <p className="text-sm font-medium text-primary">{uploadForm.data.file ? uploadForm.data.file.name : 'Click to select a file'}</p>
                            <p className="mt-1 text-xs text-primary">PDF, Word, Excel, Images up to 10MB</p>
                            <input type="file" className="hidden" onChange={(e) => {
                                const f = e.target.files?.[0] ?? null;
                                uploadForm.setData('file', f);
                                if (f && !uploadForm.data.title) uploadForm.setData('title', f.name.replace(/\.[^/.]+$/, ''));
                            }} />
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Title</Label>
                                <Input value={uploadForm.data.title} onChange={(e) => uploadForm.setData('title', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Folder</Label>
                                {allFolders.length > 0 ? (
                                    <Select value={uploadForm.data.folder || '__none__'} onValueChange={(v) => uploadForm.setData('folder', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="No folder" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">No folder</SelectItem>
                                            {allFolders.map(f => <SelectItem key={f} value={f}>{f}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input value={uploadForm.data.folder} onChange={(e) => uploadForm.setData('folder', e.target.value)} placeholder="Optional folder name" />
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={uploadForm.data.category} onValueChange={(v) => uploadForm.setData('category', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map(c => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Version</Label>
                                <Input value={uploadForm.data.version} onChange={(e) => uploadForm.setData('version', e.target.value)} placeholder="v1.0" />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input type="date" value={uploadForm.data.expiry_date} onChange={(e) => uploadForm.setData('expiry_date', e.target.value)} />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox checked={uploadForm.data.portal_visible} onCheckedChange={(v) => uploadForm.setData('portal_visible', !!v)} />
                            <Label className="text-sm">Share with family portal</Label>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea value={uploadForm.data.notes} onChange={(e) => uploadForm.setData('notes', e.target.value)} className="min-h-[60px]" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowUpload(false)}>Cancel</Button>
                        <Button className="bg-primary hover:bg-primary" disabled={uploadForm.processing || !uploadForm.data.file}
                            onClick={() => uploadForm.post(`/operations/clients/${client.id}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => { uploadForm.reset(); setShowUpload(false); } })}>
                            Upload
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Edit Dialog */}
            <Dialog open={!!editingDoc} onOpenChange={(open) => !open && setEditingDoc(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Document</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5"><Label>Title</Label><Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} /></div>
                            <div className="space-y-1.5">
                                <Label>Folder</Label>
                                {allFolders.length > 0 ? (
                                    <Select value={editForm.data.folder || '__none__'} onValueChange={(v) => editForm.setData('folder', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="No folder" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">No folder</SelectItem>
                                            {allFolders.map(f => <SelectItem key={f} value={f}>{f}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input value={editForm.data.folder} onChange={(e) => editForm.setData('folder', e.target.value)} placeholder="Optional folder name" />
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={editForm.data.category} onValueChange={(v) => editForm.setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{CATEGORIES.map(c => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Version</Label><Input value={editForm.data.version} onChange={(e) => editForm.setData('version', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Expiry Date</Label><Input type="date" value={editForm.data.expiry_date} onChange={(e) => editForm.setData('expiry_date', e.target.value)} /></div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox checked={editForm.data.portal_visible} onCheckedChange={(v) => editForm.setData('portal_visible', !!v)} />
                            <Label className="text-sm">Share with family portal</Label>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} /></div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingDoc(null)}>Cancel</Button>
                        <Button disabled={editForm.processing}
                            onClick={() => editForm.put(`/operations/clients/${client.id}/documents/${editingDoc?.id}`, { preserveScroll: true, onSuccess: () => setEditingDoc(null) })}>
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
                        <DialogDescription>Enter a name for the new folder.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Folder Name</Label>
                            <Input value={newFolderName} onChange={(e) => setNewFolderName(e.target.value)} placeholder="e.g. Medical Records"
                                onKeyDown={(e) => { if (e.key === 'Enter') handleCreateFolder(); }} autoFocus />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => { setShowNewFolder(false); setNewFolderName(''); }}>Cancel</Button>
                        <Button className="bg-primary hover:bg-primary" disabled={!newFolderName.trim()} onClick={handleCreateFolder}>
                            Create Folder
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
