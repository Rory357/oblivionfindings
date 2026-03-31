import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft, Download, File, FileImage, FileSpreadsheet, FileText,
    Filter, FolderOpen, Grid3X3, LayoutList, Lock, Pencil, Plus,
    Search, Trash2, Upload, X,
} from 'lucide-react';
import { useState } from 'react';

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

const NONE = '__none__';

const CATEGORY_COLORS: Record<string, string> = {
    contract: 'bg-blue-100 text-blue-700', letter: 'bg-purple-100 text-purple-700',
    policy: 'bg-emerald-100 text-emerald-700', certificate: 'bg-amber-100 text-amber-700',
    offer: 'bg-pink-100 text-pink-700', other: 'bg-slate-100 text-slate-700',
};

function formatLabel(s: string) { return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
function formatDate(v?: string | null) { if (!v) return '\u2014'; const d = new Date(v); return isNaN(d.getTime()) ? v : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }); }
function formatBytes(b: number) { if (b < 1024) return `${b} B`; if (b < 1048576) return `${(b / 1024).toFixed(0)} KB`; return `${(b / 1048576).toFixed(1)} MB`; }

function FileIcon({ mime }: { mime: string }) {
    if (mime.includes('pdf')) return <FileText className="h-8 w-8 text-red-500" />;
    if (mime.includes('image')) return <FileImage className="h-8 w-8 text-blue-500" />;
    if (mime.includes('spreadsheet') || mime.includes('excel') || mime.includes('csv')) return <FileSpreadsheet className="h-8 w-8 text-emerald-500" />;
    if (mime.includes('word') || mime.includes('document')) return <FileText className="h-8 w-8 text-blue-600" />;
    return <File className="h-8 w-8 text-slate-400" />;
}

function isExpiringSoon(date: string | null) {
    if (!date) return false;
    const d = new Date(date);
    const now = new Date();
    const diff = (d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
    return diff > 0 && diff <= 30;
}

function isExpired(date: string | null) {
    if (!date) return false;
    return new Date(date) < new Date();
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function StaffDocuments({ profile, documents, categories, can }: Props) {
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState<string | null>(null);
    const [currentFolder, setCurrentFolder] = useState<string | null>(null);
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [showUpload, setShowUpload] = useState(false);
    const [editingDoc, setEditingDoc] = useState<Doc | null>(null);
    const [deletingDoc, setDeletingDoc] = useState<Doc | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'People', href: '/hr/people' },
        { title: profile.name, href: `/hr/people/${profile.id}` },
        { title: 'Documents', href: `/hr/people/${profile.id}/documents` },
    ];

    // Filtering
    const filtered = documents.filter(d => {
        if (search) {
            const q = search.toLowerCase();
            if (!(d.title?.toLowerCase().includes(q) || d.original_name.toLowerCase().includes(q))) return false;
        }
        if (categoryFilter && d.category !== categoryFilter) return false;
        if (currentFolder !== null) {
            return (d.folder || null) === currentFolder;
        }
        return true;
    });

    // Folders
    const folders = [...new Set(documents.filter(d => d.folder).map(d => d.folder!))].sort();
    const unfiledCount = documents.filter(d => !d.folder).length;

    // Summary stats
    const totalCount = documents.length;
    const expiringCount = documents.filter(d => isExpiringSoon(d.expires_at)).length;
    const expiredCount = documents.filter(d => isExpired(d.expires_at)).length;
    const restrictedCount = documents.filter(d => d.is_restricted).length;

    // Upload form
    const uploadForm = useForm<{ file: File | null; title: string; category: string; folder: string; expires_at: string; is_restricted: boolean }>({
        file: null, title: '', category: '', folder: '', expires_at: '', is_restricted: false,
    });

    const editForm = useForm<{ title: string; category: string; folder: string; expires_at: string; is_restricted: boolean }>({
        title: '', category: '', folder: '', expires_at: '', is_restricted: false,
    });

    function openEdit(d: Doc) {
        editForm.setData({ title: d.title || '', category: d.category || '', folder: d.folder || '', expires_at: d.expires_at || '', is_restricted: d.is_restricted });
        setEditingDoc(d);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Documents - ${profile.name}`} />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Link href={`/hr/people/${profile.id}`}><Button variant="outline" size="icon"><ArrowLeft className="h-4 w-4" /></Button></Link>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Documents</h1>
                            <p className="text-sm text-muted-foreground">{profile.name}&apos;s document library</p>
                        </div>
                    </div>
                    {can.manage && (
                        <Button onClick={() => setShowUpload(true)} className="gap-1.5"><Upload className="h-4 w-4" />Upload Document</Button>
                    )}
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card><CardContent className="pt-4 text-center"><p className="text-2xl font-bold text-primary">{totalCount}</p><p className="text-xs text-muted-foreground uppercase tracking-wider">Total</p></CardContent></Card>
                    <Card><CardContent className="pt-4 text-center"><p className="text-2xl font-bold text-slate-600">{restrictedCount}</p><p className="text-xs text-muted-foreground uppercase tracking-wider">Restricted</p></CardContent></Card>
                    <Card><CardContent className="pt-4 text-center"><p className="text-2xl font-bold text-amber-600">{expiringCount}</p><p className="text-xs text-muted-foreground uppercase tracking-wider">Expiring</p></CardContent></Card>
                    <Card><CardContent className="pt-4 text-center"><p className="text-2xl font-bold text-red-600">{expiredCount}</p><p className="text-xs text-muted-foreground uppercase tracking-wider">Expired</p></CardContent></Card>
                </div>

                {/* Search + Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative flex-1 min-w-[200px]">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input placeholder="Search documents..." value={search} onChange={e => setSearch(e.target.value)} className="pl-9" />
                    </div>
                    <Select value={categoryFilter || NONE} onValueChange={v => setCategoryFilter(v === NONE ? null : v)}>
                        <SelectTrigger className="w-44"><Filter className="mr-2 h-3.5 w-3.5" /><SelectValue placeholder="All Categories" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Categories</SelectItem>
                            {categories.map(c => <SelectItem key={c} value={c}>{formatLabel(c)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="flex rounded-lg border bg-muted p-0.5">
                        <button onClick={() => setViewMode('grid')} className={`rounded-md p-1.5 ${viewMode === 'grid' ? 'bg-background shadow-sm' : ''}`}><Grid3X3 className="h-4 w-4" /></button>
                        <button onClick={() => setViewMode('list')} className={`rounded-md p-1.5 ${viewMode === 'list' ? 'bg-background shadow-sm' : ''}`}><LayoutList className="h-4 w-4" /></button>
                    </div>
                </div>

                {/* Folder breadcrumb */}
                {currentFolder !== null && (
                    <div className="flex items-center gap-2 text-sm">
                        <button onClick={() => setCurrentFolder(null)} className="text-primary hover:underline">All Documents</button>
                        <span className="text-muted-foreground">/</span>
                        <span className="font-medium">{currentFolder || 'Unfiled'}</span>
                    </div>
                )}

                {/* Content */}
                {filtered.length === 0 && currentFolder === null && folders.length === 0 ? (
                    <Card><CardContent className="py-16 text-center">
                        <FolderOpen className="mx-auto mb-3 h-10 w-10 text-muted-foreground/30" />
                        <p className="font-medium text-muted-foreground">No documents yet</p>
                        <p className="mt-1 text-sm text-muted-foreground/70">Upload the first document to get started</p>
                        {can.manage && <Button onClick={() => setShowUpload(true)} variant="outline" size="sm" className="mt-4 gap-1.5"><Upload className="h-4 w-4" />Upload Document</Button>}
                    </CardContent></Card>
                ) : viewMode === 'grid' ? (
                    <div>
                        {/* Folder cards (root only) */}
                        {currentFolder === null && folders.length > 0 && (
                            <div className="mb-6">
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                    {folders.map(f => {
                                        const count = documents.filter(d => d.folder === f).length;
                                        return (
                                            <button key={f} onClick={() => setCurrentFolder(f)} className="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 transition-colors hover:bg-muted/50">
                                                <FolderOpen className="h-8 w-8 text-amber-500" />
                                                <span className="text-sm font-medium truncate w-full text-center">{f}</span>
                                                <span className="text-xs text-muted-foreground">{count} file{count !== 1 ? 's' : ''}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Unfiled header */}
                        {currentFolder === null && folders.length > 0 && unfiledCount > 0 && (
                            <div className="mb-3 flex items-center gap-2"><FolderOpen className="h-4 w-4 text-muted-foreground" /><span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Unfiled Documents</span><span className="text-xs text-muted-foreground">{unfiledCount}</span></div>
                        )}

                        {/* Document grid */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                            {(currentFolder === null ? filtered.filter(d => !d.folder) : filtered).map(d => (
                                <div key={d.id} className="group relative flex flex-col items-center gap-2 rounded-xl border bg-card p-4 transition-colors hover:bg-muted/50">
                                    <FileIcon mime={d.mime_type} />
                                    <p className="text-sm font-medium text-center truncate w-full">{d.title || d.original_name}</p>
                                    <div className="flex flex-wrap justify-center gap-1">
                                        {d.category && <Badge variant="outline" className={`text-[10px] ${CATEGORY_COLORS[d.category] || ''}`}>{formatLabel(d.category)}</Badge>}
                                        {d.is_restricted && <Lock className="h-3 w-3 text-muted-foreground" />}
                                        {isExpired(d.expires_at) && <Badge variant="outline" className="text-[10px] border-red-200 bg-red-50 text-red-600">Expired</Badge>}
                                        {isExpiringSoon(d.expires_at) && !isExpired(d.expires_at) && <Badge variant="outline" className="text-[10px] border-amber-200 bg-amber-50 text-amber-600">Expiring</Badge>}
                                    </div>
                                    <p className="text-[10px] text-muted-foreground">{formatBytes(d.size_bytes)}</p>
                                    {/* Actions overlay */}
                                    <div className="absolute right-1 top-1 flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                                        <a href={`/hr/people/${profile.id}/documents/${d.id}/download`}><Button variant="ghost" size="sm" className="h-7 w-7 p-0"><Download className="h-3 w-3" /></Button></a>
                                        {can.manage && <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={() => openEdit(d)}><Pencil className="h-3 w-3" /></Button>}
                                        {can.manage && <Button variant="ghost" size="sm" className="h-7 w-7 p-0 text-red-600" onClick={() => setDeletingDoc(d)}><Trash2 className="h-3 w-3" /></Button>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : (
                    /* List view */
                    <Card><CardContent className="p-0">
                        <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Name</th>
                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Folder</th>
                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Category</th>
                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Expires</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Size</th>
                            <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                        </tr></thead><tbody className="divide-y">
                            {filtered.map(d => (
                                <tr key={d.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3"><div className="flex items-center gap-2"><FileIcon mime={d.mime_type} /><div><p className="font-medium">{d.title || d.original_name}</p><p className="text-xs text-muted-foreground">{d.original_name}</p></div>{d.is_restricted && <Lock className="h-3.5 w-3.5 text-muted-foreground" />}</div></td>
                                    <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">{d.folder || '\u2014'}</td>
                                    <td className="hidden px-4 py-3 md:table-cell">{d.category ? <Badge variant="outline" className={CATEGORY_COLORS[d.category] || ''}>{formatLabel(d.category)}</Badge> : '\u2014'}</td>
                                    <td className="hidden px-4 py-3 lg:table-cell">{d.expires_at ? <span className={isExpired(d.expires_at) ? 'text-red-600 font-medium' : isExpiringSoon(d.expires_at) ? 'text-amber-600' : 'text-muted-foreground'}>{formatDate(d.expires_at)}</span> : '\u2014'}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{formatBytes(d.size_bytes)}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <a href={`/hr/people/${profile.id}/documents/${d.id}/download`}><Button variant="ghost" size="sm" className="h-8 w-8 p-0"><Download className="h-3.5 w-3.5" /></Button></a>
                                            {can.manage && <Button variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => openEdit(d)}><Pencil className="h-3.5 w-3.5" /></Button>}
                                            {can.manage && <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-red-600" onClick={() => setDeletingDoc(d)}><Trash2 className="h-3.5 w-3.5" /></Button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody></table>
                    </CardContent></Card>
                )}
            </div>

            {/* Upload Dialog */}
            <Dialog open={showUpload} onOpenChange={v => !v && setShowUpload(false)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader><DialogTitle>Upload Document</DialogTitle><DialogDescription>Upload a document for {profile.name}.</DialogDescription></DialogHeader>
                    <form onSubmit={e => { e.preventDefault(); uploadForm.post(`/hr/people/${profile.id}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => { uploadForm.reset(); setShowUpload(false); } }); }} className="space-y-4">
                        <div className="space-y-2">
                            <Label>File *</Label>
                            <Input type="file" onChange={e => { const f = e.target.files?.[0]; if (f) { uploadForm.setData('file', f); if (!uploadForm.data.title) uploadForm.setData('title', f.name.replace(/\.[^.]+$/, '')); } }} />
                            {uploadForm.errors.file && <p className="text-xs text-red-600">{uploadForm.errors.file}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Title *</Label>
                            <Input value={uploadForm.data.title} onChange={e => uploadForm.setData('title', e.target.value)} placeholder="Document title" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Category</Label>
                                <Select value={uploadForm.data.category || NONE} onValueChange={v => uploadForm.setData('category', v === NONE ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                                    <SelectContent><SelectItem value={NONE}>None</SelectItem>{categories.map(c => <SelectItem key={c} value={c}>{formatLabel(c)}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label>Folder</Label>
                                <Input value={uploadForm.data.folder} onChange={e => uploadForm.setData('folder', e.target.value)} placeholder="e.g. Employment" />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Expiry Date</Label>
                                <Input type="date" value={uploadForm.data.expires_at} onChange={e => uploadForm.setData('expires_at', e.target.value)} />
                            </div>
                            <div className="flex items-end gap-2 pb-1">
                                <input type="checkbox" id="restricted" checked={uploadForm.data.is_restricted} onChange={e => uploadForm.setData('is_restricted', e.target.checked)} className="rounded border-gray-300" />
                                <Label htmlFor="restricted">Restricted</Label>
                            </div>
                        </div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setShowUpload(false)}>Cancel</Button><Button type="submit" disabled={uploadForm.processing}>Upload</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit Dialog */}
            <Dialog open={!!editingDoc} onOpenChange={v => !v && setEditingDoc(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader><DialogTitle>Edit Document</DialogTitle></DialogHeader>
                    <form onSubmit={e => { e.preventDefault(); if (editingDoc) editForm.put(`/hr/people/${profile.id}/documents/${editingDoc.id}`, { preserveScroll: true, onSuccess: () => setEditingDoc(null) }); }} className="space-y-4">
                        <div className="space-y-2"><Label>Title *</Label><Input value={editForm.data.title} onChange={e => editForm.setData('title', e.target.value)} /></div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2"><Label>Category</Label><Select value={editForm.data.category || NONE} onValueChange={v => editForm.setData('category', v === NONE ? '' : v)}><SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger><SelectContent><SelectItem value={NONE}>None</SelectItem>{categories.map(c => <SelectItem key={c} value={c}>{formatLabel(c)}</SelectItem>)}</SelectContent></Select></div>
                            <div className="space-y-2"><Label>Folder</Label><Input value={editForm.data.folder} onChange={e => editForm.setData('folder', e.target.value)} /></div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2"><Label>Expiry Date</Label><Input type="date" value={editForm.data.expires_at} onChange={e => editForm.setData('expires_at', e.target.value)} /></div>
                            <div className="flex items-end gap-2 pb-1"><input type="checkbox" id="edit-restricted" checked={editForm.data.is_restricted} onChange={e => editForm.setData('is_restricted', e.target.checked)} className="rounded border-gray-300" /><Label htmlFor="edit-restricted">Restricted</Label></div>
                        </div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setEditingDoc(null)}>Cancel</Button><Button type="submit" disabled={editForm.processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation */}
            <Dialog open={!!deletingDoc} onOpenChange={v => !v && setDeletingDoc(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader><DialogTitle>Delete Document</DialogTitle><DialogDescription>Are you sure you want to delete &quot;{deletingDoc?.title || deletingDoc?.original_name}&quot;? This cannot be undone.</DialogDescription></DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingDoc(null)}>Cancel</Button>
                        <Button variant="destructive" onClick={() => { if (deletingDoc) router.delete(`/hr/people/${profile.id}/documents/${deletingDoc.id}`, { preserveScroll: true, onSuccess: () => setDeletingDoc(null) }); }}>Delete</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
