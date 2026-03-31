import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    Download, File, FileImage, FileSpreadsheet, FileText,
    Filter, FolderOpen, Grid3X3, LayoutList, Search,
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
    created_at: string;
}

interface Props {
    documents: Doc[];
    categories: string[];
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

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
    return FILE_ICONS[ext] ?? { icon: File, color: 'text-violet-600', bg: 'bg-violet-100' };
}

const CATEGORY_COLORS: Record<string, string> = {
    contract: 'bg-blue-100 text-blue-700',
    letter: 'bg-purple-100 text-purple-700',
    policy: 'bg-emerald-100 text-emerald-700',
    certificate: 'bg-amber-100 text-amber-700',
    offer: 'bg-pink-100 text-pink-700',
    other: 'bg-slate-100 text-slate-700',
};

function formatLabel(s: string) { return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
function formatDate(v?: string | null) { if (!v) return '\u2014'; const d = new Date(v); return isNaN(d.getTime()) ? v : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }); }
function formatBytes(b: number) { if (b < 1024) return `${b} B`; if (b < 1048576) return `${(b / 1024).toFixed(0)} KB`; return `${(b / 1048576).toFixed(1)} MB`; }

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Documents', href: '/hr/my/documents' },
];

export default function MyDocuments({ documents, categories }: Props) {
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

    // Derive unique folder names
    const allFolders = useMemo(() => {
        const set = new Set<string>();
        documents.forEach(d => { if (d.folder) set.add(d.folder); });
        return Array.from(set).sort();
    }, [documents]);

    // Filtering
    const filtered = useMemo(() => {
        return documents.filter(d => {
            if (search && !(d.title ?? d.original_name ?? '').toLowerCase().includes(search.toLowerCase())) return false;
            if (categoryFilter && d.category !== categoryFilter) return false;
            if (currentFolder !== null) {
                if ((d.folder || '') !== currentFolder) return false;
            }
            return true;
        });
    }, [documents, search, categoryFilter, currentFolder]);

    const filesInCurrentView = useMemo(() => {
        if (currentFolder !== null) return filtered;
        return filtered.filter(d => !d.folder);
    }, [filtered, currentFolder]);

    const folderCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        documents.forEach(d => {
            if (d.folder) {
                if (search && !(d.title ?? d.original_name ?? '').toLowerCase().includes(search.toLowerCase())) return;
                if (categoryFilter && d.category !== categoryFilter) return;
                counts[d.folder] = (counts[d.folder] || 0) + 1;
            }
        });
        return counts;
    }, [documents, search, categoryFilter]);

    const stats = {
        total: documents.length,
        expiring: documents.filter(d => isExpiringSoon(d.expires_at)).length,
        expired: documents.filter(d => isExpired(d.expires_at)).length,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Documents" />

            <div className="space-y-4 p-4 lg:p-6">
                {/* Header */}
                <div>
                    <h1 className="text-xl font-bold">My Documents</h1>
                    <p className="text-sm text-muted-foreground">Your employment documents and records</p>
                </div>

                {/* Stats Bar */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                        <div className="text-xl font-bold text-violet-700">{stats.total}</div>
                        <div className="text-[10px] uppercase tracking-wider text-violet-500">Total</div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className={`text-xl font-bold ${stats.expiring > 0 ? 'text-amber-600' : 'text-slate-400'}`}>{stats.expiring}</div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Expiring</div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className={`text-xl font-bold ${stats.expired > 0 ? 'text-red-600' : 'text-slate-400'}`}>{stats.expired}</div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Expired</div>
                    </div>
                </div>

                {/* Folder breadcrumb */}
                {currentFolder && (
                    <div className="flex items-center gap-2 text-sm">
                        <button onClick={() => setCurrentFolder(null)} className="text-violet-600 hover:underline">All Documents</button>
                        <span className="text-muted-foreground">/</span>
                        <span className="font-medium">{currentFolder}</span>
                    </div>
                )}

                {/* Toolbar */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-white/50 p-3 shadow-sm">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search documents..." className="h-9 pl-8 text-sm" value={search} onChange={e => setSearch(e.target.value)} />
                    </div>
                    <Select value={categoryFilter || 'ALL'} onValueChange={v => setCategoryFilter(v === 'ALL' ? '' : v)}>
                        <SelectTrigger className="h-9 w-[150px] text-xs">
                            <Filter className="mr-1 h-3 w-3" />
                            <SelectValue placeholder="All Categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ALL">All Categories</SelectItem>
                            {categories.map(c => <SelectItem key={c} value={c}>{formatLabel(c)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="flex rounded-lg border">
                        <Button variant={viewMode === 'grid' ? 'default' : 'ghost'} size="sm" className="h-9 rounded-r-none px-2.5" onClick={() => setViewMode('grid')}>
                            <Grid3X3 className="h-4 w-4" />
                        </Button>
                        <Button variant={viewMode === 'list' ? 'default' : 'ghost'} size="sm" className="h-9 rounded-l-none px-2.5" onClick={() => setViewMode('list')}>
                            <LayoutList className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {/* Documents */}
                {filesInCurrentView.length === 0 && (currentFolder !== null || Object.keys(folderCounts).length === 0) ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                <FolderOpen className="h-8 w-8 text-violet-400" />
                            </div>
                            <p className="font-medium">No Documents</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {search || categoryFilter ? 'No documents match your filters.' : currentFolder ? 'No documents in this folder yet.' : 'No documents have been shared with you yet.'}
                            </p>
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    /* Grid View */
                    <div className="space-y-6">
                        {/* Folder cards (only at root level) */}
                        {currentFolder === null && Object.keys(folderCounts).length > 0 && (
                            <div>
                                <div className="mb-2 flex items-center gap-2">
                                    <FolderOpen className="h-4 w-4 text-violet-500" />
                                    <span className="text-sm font-semibold">Folders</span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    {Object.entries(folderCounts).sort(([a], [b]) => a.localeCompare(b)).map(([folder, count]) => (
                                        <button key={folder} onClick={() => setCurrentFolder(folder)}
                                            className="flex flex-col items-center rounded-xl border bg-white p-4 transition-all hover:shadow-md hover:-translate-y-0.5 hover:border-violet-300">
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
                                {currentFolder === null && Object.keys(folderCounts).length > 0 && <div className="mb-2 flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-violet-500" />
                                    <span className="text-sm font-semibold">Unfiled Documents</span>
                                    <Badge variant="secondary" className="text-[10px]">{filesInCurrentView.length}</Badge>
                                </div>}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                    {filesInCurrentView.map(d => {
                                        const fi = getFileInfo(d.mime_type, d.original_name);
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expires_at);
                                        const expiring = isExpiringSoon(d.expires_at);
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
                                                    {expired && <Badge className="h-4 border-0 bg-red-100 px-1 text-[8px] text-red-600">Expired</Badge>}
                                                    {expiring && !expired && <Badge className="h-4 border-0 bg-amber-100 px-1 text-[8px] text-amber-600">Expiring</Badge>}
                                                    {d.category && <Badge variant="outline" className={`h-4 border-0 px-1 text-[8px] ${CATEGORY_COLORS[d.category] || 'bg-slate-100 text-slate-600'}`}>{formatLabel(d.category)}</Badge>}
                                                </div>
                                                {/* Hover download action */}
                                                <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 rounded-b-xl bg-gradient-to-t from-white via-white to-transparent pb-2 pt-6 opacity-0 transition-opacity group-hover:opacity-100">
                                                    <a href={`/hr/my/documents/${d.id}/download`} className="flex h-7 w-7 items-center justify-center rounded-full bg-violet-100 text-violet-600 hover:bg-violet-200">
                                                        <Download className="h-3.5 w-3.5" />
                                                    </a>
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
                                            <tr key={folder} className="border-b hover:bg-slate-50 cursor-pointer" onClick={() => setCurrentFolder(folder)}>
                                                <td className="px-4 py-2.5" colSpan={5}>
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
                                    <tr className="border-b bg-slate-50 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium">Name</th>
                                        <th className="hidden px-4 py-2.5 font-medium sm:table-cell">Folder</th>
                                        <th className="hidden px-4 py-2.5 font-medium md:table-cell">Category</th>
                                        <th className="hidden px-4 py-2.5 font-medium lg:table-cell">Expires</th>
                                        <th className="px-4 py-2.5 font-medium">Size</th>
                                        <th className="px-4 py-2.5 font-medium text-right">Download</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filesInCurrentView.map(d => {
                                        const fi = getFileInfo(d.mime_type, d.original_name);
                                        const IconComp = fi.icon;
                                        const expired = isExpired(d.expires_at);
                                        const expiring = isExpiringSoon(d.expires_at);
                                        return (
                                            <tr key={d.id} className="border-b last:border-0 hover:bg-slate-50">
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center gap-2.5">
                                                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${fi.bg}`}>
                                                            <IconComp className={`h-4 w-4 ${fi.color}`} />
                                                        </div>
                                                        <p className="font-medium">{d.title || d.original_name}</p>
                                                    </div>
                                                </td>
                                                <td className="hidden px-4 py-2.5 text-muted-foreground sm:table-cell">{d.folder || '\u2014'}</td>
                                                <td className="hidden px-4 py-2.5 md:table-cell">
                                                    {d.category ? <Badge className={`border-0 text-[10px] capitalize ${CATEGORY_COLORS[d.category] ?? 'bg-slate-100 text-slate-600'}`}>{formatLabel(d.category)}</Badge> : '\u2014'}
                                                </td>
                                                <td className="hidden px-4 py-2.5 lg:table-cell">
                                                    {d.expires_at ? (
                                                        <span className={expired ? 'font-medium text-red-600' : expiring ? 'font-medium text-amber-600' : 'text-muted-foreground'}>{formatDate(d.expires_at)}</span>
                                                    ) : <span className="text-muted-foreground">{'\u2014'}</span>}
                                                </td>
                                                <td className="px-4 py-2.5 text-muted-foreground">{formatBytes(d.size_bytes)}</td>
                                                <td className="px-4 py-2.5 text-right">
                                                    <a href={`/hr/my/documents/${d.id}/download`} className="flex h-7 w-7 items-center justify-center rounded-lg hover:bg-slate-100 ml-auto">
                                                        <Download className="h-3.5 w-3.5 text-violet-600" />
                                                    </a>
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
        </AppLayout>
    );
}
