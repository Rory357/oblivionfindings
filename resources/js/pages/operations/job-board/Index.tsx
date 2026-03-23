import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Briefcase, CalendarDays, CheckCircle2, Clock, Hand, MapPin, Search, UserCheck } from 'lucide-react';

const ANY = '__ANY__';

type JobPost = {
    id: number;
    title: string;
    status: string;
    date: string;
    start_time: string;
    end_time: string;
    location: string | null;
    required_skills: string[];
    client: { id: number; first_name: string; last_name: string } | null;
    claimed_by: { id: number; name: string } | null;
};

type Props = {
    jobs: {
        data: JobPost[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        open: number;
        claimed: number;
        filled_today: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    open: 'outline',
    claimed: 'secondary',
    approved: 'default',
    filled: 'default',
    cancelled: 'destructive',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function JobBoardIndex({ jobs, filters, stats }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/job-board', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Job Board" />
            <PageHeader
                title="Job Board"
                description="Open shifts and positions available for support workers."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard label="Open Positions" value={stats?.open ?? 0} icon={Briefcase} color="indigo" />
                    <OpsStatCard label="Claimed" value={stats?.claimed ?? 0} icon={Hand} color="amber" />
                    <OpsStatCard label="Filled Today" value={stats?.filled_today ?? 0} icon={CheckCircle2} color="emerald" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search positions..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="claimed">Claimed</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="filled">Filled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Card Grid */}
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {jobs.data.length === 0 && (
                        <div className="col-span-full">
                            <Card>
                                <CardContent className="flex flex-col items-center justify-center py-16">
                                    <Briefcase className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                    <h2 className="text-lg font-semibold text-muted-foreground">No Open Positions</h2>
                                    <p className="mt-1 text-sm text-muted-foreground/80">All shifts are currently filled. Check back later.</p>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                    {jobs.data.map((job) => (
                        <Card key={job.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="p-4">
                                <div className="flex items-start justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-semibold">{job.title}</span>
                                            <Badge variant={STATUS_VARIANTS[job.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                                {job.status}
                                            </Badge>
                                        </div>
                                        {job.client && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {job.client.first_name} {job.client.last_name}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="mt-3 space-y-1.5 text-xs text-muted-foreground">
                                    <div className="flex items-center gap-1.5">
                                        <CalendarDays className="h-3 w-3" />
                                        <span>{formatDate(job.date)}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Clock className="h-3 w-3" />
                                        <span>{job.start_time} - {job.end_time}</span>
                                    </div>
                                    {job.location && (
                                        <div className="flex items-center gap-1.5">
                                            <MapPin className="h-3 w-3" />
                                            <span>{job.location}</span>
                                        </div>
                                    )}
                                </div>
                                {job.required_skills.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {job.required_skills.map((skill) => (
                                            <Badge key={skill} variant="outline" className="h-4 px-1.5 text-[9px]">
                                                {skill}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                                {job.claimed_by && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                                        <UserCheck className="h-3 w-3" />
                                        <span>Claimed by: {job.claimed_by.name}</span>
                                    </div>
                                )}
                                <div className="mt-3 flex gap-2">
                                    {job.status === 'open' && (
                                        <Button size="sm" className="h-7 flex-1 text-xs">
                                            <Hand className="mr-1 h-3 w-3" /> Claim
                                        </Button>
                                    )}
                                    {job.status === 'claimed' && (
                                        <Button size="sm" variant="default" className="h-7 flex-1 text-xs">
                                            <CheckCircle2 className="mr-1 h-3 w-3" /> Approve
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {jobs.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {jobs.links.map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
