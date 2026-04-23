import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Download, Plus, FileText } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface HrDocument {
    id: number;
    title: string;
    document_type: string;
    related_user: { id: number; name: string } | null;
    storage_path: string;
    created_at: string;
    created_by_user: { name: string };
}

interface Props {
    documents: {
        data: HrDocument[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: { type: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
];

const typeColors: Record<string, string> = {
    contract: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
    policy: 'border-primary/30 text-primary bg-primary/10',
    certificate: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
    letter: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    offer: 'border-primary/30 text-primary bg-primary/10',
    other: 'border-slate-500/30 text-muted-foreground bg-slate-500/10',
};

export default function DocumentsIndex({ documents, filters, can }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/documents', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Documents" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">HR Documents</h1>
                    {can.manage && (
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/hr/documents/templates">
                                    <FileText className="mr-2 h-4 w-4" />
                                    Generate from Template
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href="/hr/documents/upload">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Upload Document
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search documents..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
                    <Select value={filters.type || '__none__'} onValueChange={(v) => applyFilter('type', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Types</SelectItem>
                            <SelectItem value="contract">Contract</SelectItem>
                            <SelectItem value="policy">Policy</SelectItem>
                            <SelectItem value="certificate">Certificate</SelectItem>
                            <SelectItem value="letter">Letter</SelectItem>
                            <SelectItem value="offer">Offer</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-left font-medium">Related Employee</th>
                                    <th className="px-4 py-3 text-left font-medium">Created By</th>
                                    <th className="px-4 py-3 text-left font-medium">Created</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {documents.data.map((doc) => {
                                    const typeClass = typeColors[doc.document_type] || typeColors.other;
                                    return (
                                        <tr key={doc.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{doc.title}</td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={typeClass}>
                                                    {doc.document_type}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {doc.related_user ? doc.related_user.name : '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {doc.created_by_user.name}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{doc.created_at}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={`/hr/documents/${doc.id}/download`} target="_blank" rel="noreferrer">
                                                        <Download className="mr-1 h-3 w-3" />
                                                        Download
                                                    </a>
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {documents.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                            No documents found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {documents.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(documents.current_page - 1) * documents.per_page + 1} to{' '}
                            {Math.min(documents.current_page * documents.per_page, documents.total)} of{' '}
                            {documents.total} results
                        </p>
                        <LaravelPagination links={documents.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
