import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    Users,
    Search,
    Plus,
    UserPlus,
    Clock,
    CheckCircle2,
    XCircle,
    PhoneCall,
    FileText,
    ArrowRight,
} from 'lucide-react';

type Candidate = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    personal_email: string;
    personal_phone?: string | null;
    source: string;
    source_detail?: string | null;
    status: string;
    created_at: string;
};

type PaginatedCandidates = {
    data: Candidate[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Pipeline = Record<string, number>;

type Props = {
    candidates: PaginatedCandidates;
    pipeline: Pipeline;
    can: Record<string, boolean>;
};

const statusConfig: Record<string, { label: string; color: string; icon: React.ElementType }> = {
    new: { label: 'New', color: 'border-blue-500/30 text-blue-400 bg-blue-500/10', icon: UserPlus },
    screening: { label: 'Screening', color: 'border-indigo-500/30 text-indigo-400 bg-indigo-500/10', icon: FileText },
    interviewing: { label: 'Interviewing', color: 'border-amber-500/30 text-amber-400 bg-amber-500/10', icon: PhoneCall },
    offered: { label: 'Offered', color: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', icon: CheckCircle2 },
    hired: { label: 'Hired', color: 'border-green-500/30 text-green-400 bg-green-500/10', icon: CheckCircle2 },
    withdrawn: { label: 'Withdrawn', color: 'border-slate-500/30 text-slate-400 bg-slate-500/10', icon: XCircle },
    rejected: { label: 'Rejected', color: 'border-red-500/30 text-red-400 bg-red-500/10', icon: XCircle },
};

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? {
        label: status,
        color: 'border-slate-500/30 text-slate-400',
        icon: Clock,
    };
    const Icon = config.icon;
    return (
        <Badge variant="outline" className={config.color}>
            <Icon className="w-3 h-3 mr-1" />
            {config.label}
        </Badge>
    );
}

export default function RecruitmentIndex({ candidates, pipeline, can }: Props) {
    const [search, setSearch] = useState('');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/hr/recruitment', { search }, { preserveState: true, replace: true });
    };

    const pipelineEntries = Object.entries(pipeline);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
            ]}
        >
            <Head title="Recruitment Pipeline" />

            <PageShell>
                <PageHeader
                    title="Recruitment Pipeline"
                    description="Track candidates through the hiring process."
                    actions={
                        can.manage ? (
                            <Button asChild>
                                <Link href="/hr/recruitment/candidates/create">
                                    <Plus className="w-4 h-4 mr-2" />
                                    Add Candidate
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {/* Pipeline Summary Cards */}
                {pipelineEntries.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {pipelineEntries.map(([status, count]) => {
                            const config = statusConfig[status] ?? {
                                label: status,
                                color: 'border-slate-500/30 text-slate-400',
                                icon: Clock,
                            };
                            const Icon = config.icon;
                            return (
                                <Card key={status}>
                                    <CardContent className="flex items-center gap-4 py-4">
                                        <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${config.color}`}>
                                            <Icon className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <div className="text-2xl font-bold">{count}</div>
                                            <div className="text-sm text-muted-foreground">{config.label}</div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {/* Search */}
                <form onSubmit={handleSearch} className="flex items-center gap-2">
                    <div className="relative flex-1 max-w-sm">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search candidates..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="pl-9"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>

                {/* Candidates Table */}
                <div className="overflow-hidden rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-slate-50/5">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Name</th>
                                <th className="px-4 py-3 text-left font-medium">Email</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Source</th>
                                <th className="px-4 py-3 text-left font-medium">Applied</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {candidates.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                        <Users className="mx-auto mb-3 h-10 w-10 opacity-50" />
                                        <p>No candidates found.</p>
                                    </td>
                                </tr>
                            ) : (
                                candidates.data.map((candidate) => (
                                    <tr key={candidate.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/hr/recruitment/candidates/${candidate.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {candidate.first_name} {candidate.last_name}
                                            </Link>
                                            {candidate.preferred_name && (
                                                <div className="text-xs text-muted-foreground">
                                                    "{candidate.preferred_name}"
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {candidate.personal_email}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={candidate.status} />
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground capitalize">
                                            {candidate.source?.replace(/_/g, ' ') || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {new Date(candidate.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/hr/recruitment/candidates/${candidate.id}`}>
                                                    View
                                                    <ArrowRight className="ml-1 h-3 w-3" />
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {candidates.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <div className="text-sm text-muted-foreground">
                            Showing {(candidates.current_page - 1) * candidates.per_page + 1} to{' '}
                            {Math.min(candidates.current_page * candidates.per_page, candidates.total)} of{' '}
                            {candidates.total} candidates
                        </div>
                        <div className="flex items-center gap-1">
                            {candidates.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
