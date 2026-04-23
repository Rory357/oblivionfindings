import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type BreadcrumbItem } from '@/types';
import { Plus, Eye, Pencil, Globe, XCircle, Copy, FileText, Search, Briefcase, Clock, CheckCircle2, AlertCircle, Lock, Wifi } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { useState } from 'react';
import { statusConfig, employmentTypeLabels } from '@/lib/job-posting-constants';
import type { JobPostingListItem } from '@/types/job-postings';

type Props = {
    postings: {
        data: JobPostingListItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    stats: { total: number; published: number; draft: number; pending_approval: number; closed: number };
    filters: { status: string | null; search: string | null };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Job Postings', href: '/hr/job-postings' },
];

function daysUntilClose(closesAt: string | null): { text: string; urgent: boolean } | null {
    if (!closesAt) return null;
    const diff = Math.ceil((new Date(closesAt).getTime() - Date.now()) / (1000 * 60 * 60 * 24));
    if (diff < 0) return { text: 'Expired', urgent: true };
    if (diff === 0) return { text: 'Closes today', urgent: true };
    if (diff <= 7) return { text: `${diff}d left`, urgent: true };
    return { text: `${diff}d left`, urgent: false };
}

export default function JobPostingIndex({ postings, stats, filters, can }: Props) {
    const [searchValue, setSearchValue] = useState(filters.search || '');

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/job-postings', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const onSearch = () => {
        onFilter({ search: searchValue || null });
    };

    const copyPublicLink = (slug: string | null) => {
        if (slug) {
            navigator.clipboard.writeText(`${window.location.origin}/careers/${slug}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Job Postings" />
            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
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

                {/* KPI Stats */}
                <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <Card className="cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => onFilter({ status: null })}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-primary/10 p-2"><Briefcase className="h-4 w-4 text-primary" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{stats.total}</p>
                                    <p className="text-xs text-muted-foreground">Total</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => onFilter({ status: 'published' })}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-500/10 p-2"><Globe className="h-4 w-4 text-emerald-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{stats.published}</p>
                                    <p className="text-xs text-muted-foreground">Published</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => onFilter({ status: 'draft' })}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-slate-500/10 p-2"><FileText className="h-4 w-4 text-muted-foreground" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{stats.draft}</p>
                                    <p className="text-xs text-muted-foreground">Draft</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => onFilter({ status: 'pending_approval' })}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-amber-500/10 p-2"><Clock className="h-4 w-4 text-amber-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{stats.pending_approval}</p>
                                    <p className="text-xs text-muted-foreground">Pending</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="cursor-pointer hover:bg-accent/50 transition-colors" onClick={() => onFilter({ status: 'closed' })}>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-red-500/10 p-2"><XCircle className="h-4 w-4 text-red-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{stats.closed}</p>
                                    <p className="text-xs text-muted-foreground">Closed</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Search & Filter */}
                <div className="flex flex-col sm:flex-row gap-3">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            value={searchValue}
                            onChange={e => setSearchValue(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') onSearch(); }}
                            placeholder="Search by title, department, or location..."
                            className="pl-9"
                        />
                    </div>
                    <div className="flex gap-2 flex-wrap">
                        {['all', 'draft', 'pending_approval', 'published', 'closed'].map(s => (
                            <Button
                                key={s}
                                variant={(!filters.status && s === 'all') || filters.status === s ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => onFilter({ status: s === 'all' ? null : s })}
                            >
                                <span className="capitalize">{s === 'pending_approval' ? 'Pending' : s}</span>
                            </Button>
                        ))}
                    </div>
                </div>

                {/* Postings List */}
                <div className="grid gap-3">
                    {postings.data.map(posting => {
                        const config = statusConfig[posting.status] || statusConfig.draft;
                        const closing = daysUntilClose(posting.closes_at);
                        return (
                            <Card key={posting.id} className="hover:bg-accent/30 transition-colors">
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <Link href={`/hr/job-postings/${posting.id}`} className="font-semibold text-sm hover:underline truncate">
                                                    {posting.title}
                                                </Link>
                                                <Badge variant="outline" className={config.className}>{config.label}</Badge>
                                                <Badge variant="secondary" className="text-xs">{employmentTypeLabels[posting.employment_type] || posting.employment_type}</Badge>
                                                {posting.is_remote && (
                                                    <Badge variant="outline" className="text-xs gap-1 border-blue-500/30 text-blue-400 bg-blue-500/10">
                                                        <Wifi className="h-3 w-3" /> Remote
                                                    </Badge>
                                                )}
                                                {posting.is_internal && (
                                                    <Badge variant="outline" className="text-xs gap-1 border-primary/30 text-primary bg-primary/10">
                                                        <Lock className="h-3 w-3" /> Internal
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-muted-foreground">
                                                {posting.department && <span>{posting.department}</span>}
                                                {posting.location && <span>{posting.location}</span>}
                                                <span>{posting.applications_count} applications</span>
                                                <span>{posting.views_count} views</span>
                                                {posting.hiring_manager && <span>Manager: {posting.hiring_manager}</span>}
                                                {posting.published_at && <span>Published: {posting.published_at}</span>}
                                                {closing && (
                                                    <span className={closing.urgent ? 'text-amber-400 font-medium' : ''}>
                                                        {closing.text}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex gap-1.5 shrink-0">
                                            {can.manage && posting.status === 'draft' && (
                                                <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/publish`)}>
                                                    <Globe className="mr-1 h-3.5 w-3.5" /> Publish
                                                </Button>
                                            )}
                                            {can.manage && posting.status === 'pending_approval' && (
                                                <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/approve`)}>
                                                    <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Approve
                                                </Button>
                                            )}
                                            {can.manage && posting.status === 'published' && (
                                                <>
                                                    <Button variant="ghost" size="sm" onClick={() => copyPublicLink(posting.slug)} title="Copy public link">
                                                        <Copy className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/close`)}>
                                                        <XCircle className="mr-1 h-3.5 w-3.5" /> Close
                                                    </Button>
                                                </>
                                            )}
                                            {can.manage && (
                                                <>
                                                    <Button variant="ghost" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/duplicate`)} title="Duplicate">
                                                        <Copy className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/hr/job-postings/${posting.id}/edit`}><Pencil className="h-3.5 w-3.5" /></Link>
                                                    </Button>
                                                </>
                                            )}
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/hr/job-postings/${posting.id}`}><Eye className="h-3.5 w-3.5" /></Link>
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {postings.data.length === 0 && (
                        <Card>
                            <CardContent className="py-16 text-center">
                                <Briefcase className="mx-auto h-12 w-12 text-muted-foreground/40 mb-4" />
                                <p className="text-muted-foreground font-medium">No job postings found</p>
                                <p className="text-sm text-muted-foreground mt-1">Create your first posting to get started.</p>
                                {can.manage && (
                                    <Button asChild className="mt-4" size="sm">
                                        <Link href="/hr/job-postings/create">
                                            <Plus className="mr-1.5 h-4 w-4" /> New Posting
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>

                {postings.links?.length > 3 && <LaravelPagination links={postings.links} />}
            </div>
        </AppLayout>
    );
}
