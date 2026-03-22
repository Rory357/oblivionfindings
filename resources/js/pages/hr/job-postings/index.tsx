import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Plus, Eye, Pencil, Globe, XCircle } from 'lucide-react';

type Posting = {
    id: number;
    title: string;
    department: string | null;
    location: string | null;
    employment_type: string;
    status: string;
    published_at: string | null;
    closes_at: string | null;
    applications_count: number;
    position: { id: number; title: string } | null;
    created_by: string | null;
    created_at: string;
};

type Props = {
    postings: {
        data: Posting[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: string | null };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Job Postings', href: '/hr/job-postings' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-slate-500/30 text-slate-400 bg-slate-500/10', label: 'Draft' },
    published: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Published' },
    closed: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Closed' },
};

const typeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

export default function JobPostingIndex({ postings, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/job-postings', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Job Postings" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Job Postings</h1>
                        <p className="text-sm text-muted-foreground">Manage job listings for your career portal</p>
                    </div>
                    {can.manage && (
                        <Button asChild size="sm">
                            <Link href="/hr/job-postings/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Posting
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Status Filter */}
                <div className="flex gap-2">
                    {['all', 'draft', 'published', 'closed'].map((s) => (
                        <Button
                            key={s}
                            variant={(!filters.status && s === 'all') || filters.status === s ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => onFilter({ status: s === 'all' ? null : s })}
                        >
                            <span className="capitalize">{s}</span>
                        </Button>
                    ))}
                </div>

                {/* Postings List */}
                <div className="grid gap-4">
                    {postings.data.map((posting) => {
                        const config = statusConfig[posting.status] || statusConfig.draft;
                        return (
                            <Card key={posting.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <CardTitle className="text-base">{posting.title}</CardTitle>
                                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                                <Badge variant="secondary">
                                                    {typeLabels[posting.employment_type] || posting.employment_type}
                                                </Badge>
                                                {posting.department && (
                                                    <span className="text-xs text-muted-foreground">{posting.department}</span>
                                                )}
                                                {posting.location && (
                                                    <span className="text-xs text-muted-foreground">{posting.location}</span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            {can.manage && posting.status === 'draft' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.post(`/hr/job-postings/${posting.id}/publish`)}
                                                >
                                                    <Globe className="mr-1.5 h-3.5 w-3.5" />
                                                    Publish
                                                </Button>
                                            )}
                                            {can.manage && posting.status === 'published' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.post(`/hr/job-postings/${posting.id}/close`)}
                                                >
                                                    <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                                    Close
                                                </Button>
                                            )}
                                            {can.manage && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/hr/job-postings/${posting.id}/edit`}>
                                                        <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                                        Edit
                                                    </Link>
                                                </Button>
                                            )}
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/hr/job-postings/${posting.id}`}>
                                                    <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="flex gap-6 text-sm text-muted-foreground">
                                        <span>{posting.applications_count} applications</span>
                                        {posting.published_at && <span>Published: {posting.published_at}</span>}
                                        {posting.closes_at && <span>Closes: {posting.closes_at}</span>}
                                        {posting.created_by && <span>By: {posting.created_by}</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {postings.data.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No job postings found. Create your first posting to get started.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                {postings.links?.length > 3 && (
                    <div className="flex flex-wrap gap-2">
                        {postings.links.map((l, i) => (
                            <Button
                                key={i}
                                variant={l.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}
                            >
                                <span dangerouslySetInnerHTML={{ __html: l.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
