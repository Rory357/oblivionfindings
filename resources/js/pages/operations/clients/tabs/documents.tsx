import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    FileImage,
    FileSpreadsheet,
    FileText,
    Filter,
    FolderOpen,
    Globe,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export type ClientDocumentItem = {
    id: number;
    title?: string | null;
    category?: string | null;
    folder?: string | null;
    version?: string | null;
    effective_date?: string | null;
    expiry_date?: string | null;
    portal_visible?: boolean;
    notes?: string | null;
    original_name?: string | null;
    mime_type?: string | null;
    size_bytes?: number | null;
    created_at?: string | null;
};

type DocumentsTabProps = {
    clientId: number;
    clientName: string;
    documents?: ClientDocumentItem[];
};

const CATEGORY_OPTIONS = [
    { value: 'care_plan', label: 'Care Plans' },
    { value: 'assessment', label: 'Assessments' },
    { value: 'medical', label: 'Medical' },
    { value: 'legal', label: 'Legal' },
    { value: 'policy', label: 'Policies' },
    { value: 'consent', label: 'Consents' },
    { value: 'other', label: 'Other' },
];

const CATEGORY_COLORS: Record<string, string> = {
    care_plan: 'bg-primary/10 text-primary',
    assessment: 'bg-status-info-bg text-status-info',
    medical: 'bg-status-critical-bg text-status-critical',
    legal: 'bg-status-warning-bg text-status-warning',
    policy: 'bg-status-success-bg text-status-success',
    consent: 'bg-primary/10 text-primary',
};

const FILE_ICON_BY_EXT: Record<
    string,
    { icon: typeof FileText; color: string; bg: string }
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

function getFileIcon(name?: string | null) {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
    return (
        FILE_ICON_BY_EXT[ext] ?? {
            icon: FileText,
            color: 'text-primary',
            bg: 'bg-primary/10',
        }
    );
}

const THIRTY_DAYS_MS = 30 * 86400000;

function expiryState(value?: string | null): 'expired' | 'expiring' | null {
    if (!value) return null;
    const ts = new Date(value).getTime();
    if (Number.isNaN(ts)) return null;
    const delta = ts - Date.now();
    if (delta < 0) return 'expired';
    if (delta < THIRTY_DAYS_MS) return 'expiring';
    return null;
}

export function DocumentsTab({
    clientId,
    clientName,
    documents,
}: DocumentsTabProps) {
    const list = useMemo(() => documents ?? [], [documents]);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string>('all');
    const [visibility, setVisibility] = useState<'all' | 'portal' | 'internal'>(
        'all',
    );
    const [expiry, setExpiry] = useState<
        'all' | 'expired' | 'expiring' | 'current'
    >('all');

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();
        return list.filter((d) => {
            if (category !== 'all' && (d.category ?? '') !== category) {
                return false;
            }
            if (visibility === 'portal' && !d.portal_visible) return false;
            if (visibility === 'internal' && d.portal_visible) return false;
            const state = expiryState(d.expiry_date);
            if (expiry === 'expired' && state !== 'expired') return false;
            if (expiry === 'expiring' && state !== 'expiring') return false;
            if (expiry === 'current' && state !== null) return false;
            if (term) {
                const haystack = [
                    d.title,
                    d.original_name,
                    d.category,
                    d.folder,
                    d.notes,
                ]
                    .map((v) => String(v ?? '').toLowerCase())
                    .join(' ');
                if (!haystack.includes(term)) return false;
            }
            return true;
        });
    }, [list, search, category, visibility, expiry]);

    const grouped = useMemo(() => {
        const acc: Record<string, ClientDocumentItem[]> = {};
        for (const d of filtered) {
            const folder = (d.folder && d.folder.trim()) || 'Unfiled';
            if (!acc[folder]) acc[folder] = [];
            acc[folder].push(d);
        }
        return acc;
    }, [filtered]);

    const totalExpired = list.filter(
        (d) => expiryState(d.expiry_date) === 'expired',
    ).length;
    const totalExpiring = list.filter(
        (d) => expiryState(d.expiry_date) === 'expiring',
    ).length;
    const totalPortal = list.filter((d) => d.portal_visible).length;

    const activeFilters =
        (search ? 1 : 0) +
        (category !== 'all' ? 1 : 0) +
        (visibility !== 'all' ? 1 : 0) +
        (expiry !== 'all' ? 1 : 0);

    const clearFilters = () => {
        setSearch('');
        setCategory('all');
        setVisibility('all');
        setExpiry('all');
    };

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-xl font-bold text-primary">
                        {list.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-primary uppercase">
                        Total
                    </div>
                </div>
                <div className="rounded-xl border bg-status-critical-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-critical">
                        {totalExpired}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-critical uppercase">
                        Expired
                    </div>
                </div>
                <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-warning">
                        {totalExpiring}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-warning uppercase">
                        Expiring 30 d
                    </div>
                </div>
                <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-info">
                        {totalPortal}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-info uppercase">
                        Family portal
                    </div>
                </div>
            </div>

            {/* Filter bar + Manage CTA */}
            <Card className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
                <div className="relative flex-1">
                    <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Search documents…"
                        className="h-9 pl-8 text-sm"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <Select value={category} onValueChange={setCategory}>
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[160px]">
                        <Filter className="mr-1 h-3 w-3" />
                        <SelectValue placeholder="All categories" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All categories</SelectItem>
                        {CATEGORY_OPTIONS.map((c) => (
                            <SelectItem key={c.value} value={c.value}>
                                {c.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={visibility}
                    onValueChange={(v) =>
                        setVisibility(v as 'all' | 'portal' | 'internal')
                    }
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[150px]">
                        <Globe className="mr-1 h-3 w-3" />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All visibility</SelectItem>
                        <SelectItem value="portal">Family portal</SelectItem>
                        <SelectItem value="internal">Internal only</SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    value={expiry}
                    onValueChange={(v) =>
                        setExpiry(
                            v as 'all' | 'expired' | 'expiring' | 'current',
                        )
                    }
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[150px]">
                        <Clock className="mr-1 h-3 w-3" />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any expiry</SelectItem>
                        <SelectItem value="expired">Expired</SelectItem>
                        <SelectItem value="expiring">
                            Expiring in 30 d
                        </SelectItem>
                        <SelectItem value="current">Current</SelectItem>
                    </SelectContent>
                </Select>
                {activeFilters > 0 ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={clearFilters}
                        className="gap-1.5"
                    >
                        <SlidersHorizontal className="h-3.5 w-3.5" />
                        Clear ({activeFilters})
                    </Button>
                ) : null}
                <Button size="sm" className="gap-1.5" asChild>
                    <Link href={`/operations/clients/${clientId}/documents`}>
                        Manage documents
                    </Link>
                </Button>
            </Card>

            {/* Grid grouped by folder */}
            {filtered.length === 0 ? (
                <EmptyState
                    icon={
                        list.length === 0 || activeFilters === 0
                            ? FolderOpen
                            : AlertTriangle
                    }
                    title={
                        list.length === 0
                            ? 'No documents'
                            : 'No documents match your filters'
                    }
                    description={
                        list.length === 0
                            ? `Upload care plans, assessments, and other paperwork for ${clientName}.`
                            : 'Try clearing some filters or widening the search term.'
                    }
                    action={
                        list.length === 0 ? (
                            <Button size="sm" asChild>
                                <Link
                                    href={`/operations/clients/${clientId}/documents`}
                                >
                                    Upload first document
                                </Link>
                            </Button>
                        ) : activeFilters > 0 ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={clearFilters}
                            >
                                Clear filters
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                Object.entries(grouped).map(([folder, docs]) => (
                    <div key={folder}>
                        <div className="mb-2 flex items-center gap-2">
                            <FolderOpen className="h-4 w-4 text-status-warning" />
                            <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                {folder}
                            </span>
                            <Badge
                                variant="secondary"
                                className="text-[10px]"
                            >
                                {docs.length}
                            </Badge>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {docs.map((d) => {
                                const fi = getFileIcon(d.original_name);
                                const state = expiryState(d.expiry_date);
                                const Icon = fi.icon;
                                return (
                                    <a
                                        key={d.id}
                                        href={`/operations/clients/${clientId}/documents/${d.id}/download`}
                                        className={cn(
                                            'group rounded-xl border bg-card p-4 text-center transition-all hover:-translate-y-0.5 hover:shadow-md',
                                            state === 'expired' &&
                                                'border-status-critical/30',
                                            state === 'expiring' &&
                                                'border-status-warning/30',
                                        )}
                                    >
                                        <div
                                            className={cn(
                                                'mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-xl',
                                                fi.bg,
                                            )}
                                        >
                                            <Icon
                                                className={cn('h-6 w-6', fi.color)}
                                            />
                                        </div>
                                        <p className="line-clamp-2 text-xs leading-tight font-medium">
                                            {d.title ||
                                                d.original_name ||
                                                'Untitled'}
                                        </p>
                                        <div className="mt-1.5 flex flex-wrap items-center justify-center gap-1">
                                            {d.portal_visible ? (
                                                <Globe className="h-3 w-3 text-status-info" />
                                            ) : null}
                                            {state === 'expired' ? (
                                                <Badge className="h-4 border-0 bg-status-critical-bg px-1 text-[8px] text-status-critical">
                                                    Expired
                                                </Badge>
                                            ) : null}
                                            {state === 'expiring' ? (
                                                <Badge className="h-4 border-0 bg-status-warning-bg px-1 text-[8px] text-status-warning">
                                                    Expiring
                                                </Badge>
                                            ) : null}
                                            {d.category ? (
                                                <Badge
                                                    className={cn(
                                                        'h-4 border-0 px-1 text-[8px]',
                                                        CATEGORY_COLORS[
                                                            d.category
                                                        ] ??
                                                            'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {d.category.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            ) : null}
                                        </div>
                                    </a>
                                );
                            })}
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

export default DocumentsTab;
