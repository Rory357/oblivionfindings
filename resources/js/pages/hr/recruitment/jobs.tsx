import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { KpiCard } from '@/components/recruitment/kpi-card';
import {
    AlertCircle, AlertTriangle, BriefcaseBusiness, CheckCircle2, Clock3,
    Globe2, UserCheck, Plus, MapPin, Users, ExternalLink, X, Search,
    LayoutGrid, List, ArrowLeft, Edit,
} from 'lucide-react';

interface Job {
    id: number;
    title: string;
    slug: string;
    position_role: string | null;
    employment_type: string;
    openings: number;
    status: string;
    summary: string | null;
    description: string | null;
    requirements: string | null;
    responsibilities: string | null;
    published_at: string | null;
    closing_at: string | null;
    site: { id: number; name: string } | null;
    default_interview_kit: { id: number; name: string } | null;
    hiring_manager: { id: number; name: string } | null;
    posting_channels: string[];
    external_posting_status: 'not_posted' | 'posted' | 'sync_failed';
    external_posted_at: string | null;
    external_sync_at: string | null;
    external_sync_error: string | null;
    metrics: {
        total_applications: number;
        active_candidates: number;
        stale_candidates: number;
        offers_in_flight: number;
        hired_candidates: number;
        conversion_rate: number;
        average_stage_age_days: number;
    };
}

interface PaginatedJobs {
    data: Job[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    jobs: PaginatedJobs;
    summary: {
        total_jobs: number;
        open_requisitions: number;
        published_jobs: number;
        closing_soon: number;
        externally_posted_jobs: number;
        external_sync_failed_jobs: number;
        active_candidates: number;
        stale_candidates: number;
        offers_in_flight: number;
        hired_candidates: number;
    };
    managerSummary: Array<{
        manager: { id: number; name: string } | null;
        open_jobs: number;
        active_candidates: number;
        stale_candidates: number;
        offers_in_flight: number;
        hired_candidates: number;
    }>;
    sites: Array<{ id: number; name: string }>;
    interviewKits: Array<{ id: number; name: string; role: string | null }>;
    hiringManagers: Array<{ id: number; name: string; email: string }>;
    statuses: string[];
    employmentTypes: string[];
    postingChannels: string[];
    filters: { search: string; status: string | null; hiring_manager_user_id: string | null };
    can: { manage: boolean };
}

const statusVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    published: 'default', draft: 'secondary', paused: 'outline', closed: 'destructive',
};

const channelIcons: Record<string, string> = {
    career_page: '🌐', linkedin: '💼', seek: '🔍', indeed: '📋', facebook: '📘',
};

function getInitials(name: string) {
    return name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2);
}

export default function RecruitmentJobs({
    jobs, summary, managerSummary, sites, interviewKits, hiringManagers,
    statuses, employmentTypes, postingChannels, filters, can,
}: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingJobId, setEditingJobId] = useState<number | null>(null);
    const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');

    const createForm = useForm({
        title: '', position_role: '', site_id: '',
        employment_type: employmentTypes[0] || 'full_time', openings: '1',
        summary: '', description: '', requirements: '', responsibilities: '',
        default_interview_kit_id: '', hiring_manager_user_id: '',
        posting_channels: [] as string[], closing_at: '',
    });

    function resetJobForm() { createForm.reset(); setEditingJobId(null); setFormOpen(false); }

    function submitJobForm(e: React.FormEvent) {
        e.preventDefault();
        if (editingJobId) {
            createForm.put(`/hr/recruitment/jobs/${editingJobId}`, { preserveScroll: true, onSuccess: resetJobForm });
        } else {
            createForm.post('/hr/recruitment/jobs', { preserveScroll: true, onSuccess: resetJobForm });
        }
    }

    function applyFilter(key: string, value: string | null) {
        router.get('/hr/recruitment/jobs', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    function publishJob(jobId: number) { router.post(`/hr/recruitment/jobs/${jobId}/publish`, {}, { preserveScroll: true }); }
    function closeJob(jobId: number) { router.post(`/hr/recruitment/jobs/${jobId}/close`, {}, { preserveScroll: true }); }
    function syncPosting(jobId: number) { router.post(`/hr/recruitment/jobs/${jobId}/sync-posting`, {}, { preserveScroll: true }); }

    function startEdit(job: Job) {
        setEditingJobId(job.id);
        createForm.setData({
            title: job.title || '', position_role: job.position_role || '',
            site_id: job.site ? String(job.site.id) : '',
            employment_type: job.employment_type || (employmentTypes[0] || 'full_time'),
            openings: String(job.openings || 1), summary: job.summary || '',
            description: job.description || '', requirements: job.requirements || '',
            responsibilities: job.responsibilities || '',
            default_interview_kit_id: job.default_interview_kit ? String(job.default_interview_kit.id) : '',
            hiring_manager_user_id: job.hiring_manager ? String(job.hiring_manager.id) : '',
            posting_channels: job.posting_channels || [], closing_at: job.closing_at || '',
        });
        setFormOpen(true);
    }

    function togglePostingChannel(channel: string, checked: boolean) {
        createForm.setData('posting_channels', checked
            ? Array.from(new Set([...createForm.data.posting_channels, channel]))
            : createForm.data.posting_channels.filter((v) => v !== channel));
    }

    return (
        <AppLayout breadcrumbs={[
            { title: 'HR', href: '/hr' },
            { title: 'Recruitment', href: '/hr/recruitment' },
            { title: 'Jobs', href: '/hr/recruitment/jobs' },
        ]}>
            <Head title="Job Requisitions" />
            <PageShell>
                <PageHeader
                    title="Job Requisitions"
                    description="Create and publish roles to the public careers page."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hr/recruitment"><ArrowLeft className="mr-2 h-4 w-4" />Pipeline</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/careers" target="_blank"><ExternalLink className="mr-2 h-4 w-4" />Careers Page</Link>
                            </Button>
                            {can.manage && (
                                <Dialog open={formOpen} onOpenChange={setFormOpen}>
                                    <DialogTrigger asChild>
                                        <Button onClick={() => { setEditingJobId(null); createForm.reset(); }}>
                                            <Plus className="mr-2 h-4 w-4" />New Job
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                                        <DialogHeader>
                                            <DialogTitle>{editingJobId ? 'Edit Job Requisition' : 'Create Job Requisition'}</DialogTitle>
                                        </DialogHeader>
                                        <form onSubmit={submitJobForm} className="space-y-4">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Title *</Label>
                                                    <Input value={createForm.data.title} onChange={(e) => createForm.setData('title', e.target.value)} placeholder="Support Worker" />
                                                    {createForm.errors.title && <p className="text-sm text-destructive">{createForm.errors.title}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Role</Label>
                                                    <Input value={createForm.data.position_role} onChange={(e) => createForm.setData('position_role', e.target.value)} placeholder="support_worker" />
                                                </div>
                                            </div>
                                            <div className="grid gap-4 md:grid-cols-3">
                                                <div className="space-y-2">
                                                    <Label>Type</Label>
                                                    <Select value={createForm.data.employment_type} onValueChange={(v) => createForm.setData('employment_type', v)}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>{employmentTypes.map((t) => <SelectItem key={t} value={t}>{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Openings</Label>
                                                    <Input type="number" min={1} max={100} value={createForm.data.openings} onChange={(e) => createForm.setData('openings', e.target.value)} />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Closing Date</Label>
                                                    <Input type="date" value={createForm.data.closing_at} onChange={(e) => createForm.setData('closing_at', e.target.value)} />
                                                </div>
                                            </div>
                                            <div className="grid gap-4 md:grid-cols-3">
                                                <div className="space-y-2">
                                                    <Label>Site</Label>
                                                    <Select value={createForm.data.site_id || '__none__'} onValueChange={(v) => createForm.setData('site_id', v === '__none__' ? '' : v)}>
                                                        <SelectTrigger><SelectValue placeholder="Any site" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">Any site</SelectItem>
                                                            {sites.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Interview Kit</Label>
                                                    <Select value={createForm.data.default_interview_kit_id || '__none__'} onValueChange={(v) => createForm.setData('default_interview_kit_id', v === '__none__' ? '' : v)}>
                                                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">None</SelectItem>
                                                            {interviewKits.map((k) => <SelectItem key={k.id} value={String(k.id)}>{k.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Hiring Manager</Label>
                                                    <Select value={createForm.data.hiring_manager_user_id || '__none__'} onValueChange={(v) => createForm.setData('hiring_manager_user_id', v === '__none__' ? '' : v)}>
                                                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">None</SelectItem>
                                                            {hiringManagers.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Posting Channels</Label>
                                                <div className="flex flex-wrap gap-2">
                                                    {postingChannels.map((ch) => (
                                                        <button key={ch} type="button"
                                                            className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors ${
                                                                createForm.data.posting_channels.includes(ch)
                                                                    ? 'bg-primary text-primary-foreground border-primary'
                                                                    : 'bg-muted hover:bg-muted/80 border-border'
                                                            }`}
                                                            onClick={() => togglePostingChannel(ch, !createForm.data.posting_channels.includes(ch))}
                                                        >
                                                            <span>{channelIcons[ch] ?? '📌'}</span>
                                                            <span className="capitalize">{ch.replace('_', ' ')}</span>
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                            <div className="space-y-2"><Label>Summary</Label><Textarea rows={2} value={createForm.data.summary} onChange={(e) => createForm.setData('summary', e.target.value)} /></div>
                                            <div className="space-y-2"><Label>Description</Label><Textarea rows={4} value={createForm.data.description} onChange={(e) => createForm.setData('description', e.target.value)} /></div>
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2"><Label>Responsibilities</Label><Textarea rows={3} value={createForm.data.responsibilities} onChange={(e) => createForm.setData('responsibilities', e.target.value)} /></div>
                                                <div className="space-y-2"><Label>Requirements</Label><Textarea rows={3} value={createForm.data.requirements} onChange={(e) => createForm.setData('requirements', e.target.value)} /></div>
                                            </div>
                                            <div className="flex justify-end gap-2">
                                                <Button type="button" variant="outline" onClick={resetJobForm}>Cancel</Button>
                                                <Button type="submit" disabled={createForm.processing}>
                                                    {createForm.processing ? 'Saving...' : editingJobId ? 'Update Job' : 'Create Draft Job'}
                                                </Button>
                                            </div>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <KpiCard label="Open Requisitions" value={summary.open_requisitions} icon={BriefcaseBusiness} color="bg-blue-500/10 text-blue-500" />
                    <KpiCard label="Active Candidates" value={summary.active_candidates} icon={UserCheck} color="bg-emerald-500/10 text-emerald-500" />
                    <KpiCard label="Stale Candidates" value={summary.stale_candidates} icon={AlertTriangle} color="bg-amber-500/10 text-amber-500" />
                    <KpiCard label="Offers In Flight" value={summary.offers_in_flight} icon={Clock3} color="bg-primary/10 text-primary" />
                    <KpiCard label="Total Hired" value={summary.hired_candidates} icon={CheckCircle2} color="bg-green-500/10 text-green-500" />
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative flex-1 max-w-sm">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input className="pl-9" placeholder="Search jobs..." defaultValue={filters.search}
                            onKeyDown={(e) => { if (e.key === 'Enter') applyFilter('search', (e.target as HTMLInputElement).value); }} />
                    </div>
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.hiring_manager_user_id || '__all__'} onValueChange={(v) => applyFilter('hiring_manager_user_id', v === '__all__' ? null : v)}>
                        <SelectTrigger className="w-52"><SelectValue placeholder="All managers" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All managers</SelectItem>
                            <SelectItem value="unassigned">Unassigned</SelectItem>
                            {hiringManagers.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="ml-auto flex items-center gap-1 rounded-lg border p-0.5">
                        <Button variant={viewMode === 'cards' ? 'secondary' : 'ghost'} size="sm" className="h-7 px-2" onClick={() => setViewMode('cards')}>
                            <LayoutGrid className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant={viewMode === 'table' ? 'secondary' : 'ghost'} size="sm" className="h-7 px-2" onClick={() => setViewMode('table')}>
                            <List className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>

                {/* Job Cards / Table */}
                {jobs.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <BriefcaseBusiness className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="text-lg font-medium">No jobs found</p>
                            <p className="text-sm">Create a job requisition to start recruiting.</p>
                        </CardContent>
                    </Card>
                ) : viewMode === 'cards' ? (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {jobs.data.map((job) => {
                            const total = job.metrics.active_candidates + job.metrics.stale_candidates + job.metrics.hired_candidates;
                            const activePct = total > 0 ? (job.metrics.active_candidates / total) * 100 : 0;
                            const stalePct = total > 0 ? (job.metrics.stale_candidates / total) * 100 : 0;
                            const hiredPct = total > 0 ? (job.metrics.hired_candidates / total) * 100 : 0;
                            return (
                                <Card key={job.id} className="hover:shadow-md transition-shadow group">
                                    <CardContent className="p-5">
                                        <div className="flex items-start justify-between gap-2 mb-3">
                                            <div className="min-w-0">
                                                <h3 className="font-semibold text-sm truncate group-hover:text-primary transition-colors">{job.title}</h3>
                                                <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
                                                    {job.position_role && <Badge variant="outline" className="text-[10px]">{job.position_role}</Badge>}
                                                    <span className="capitalize">{job.employment_type.replace('_', ' ')}</span>
                                                    {job.openings > 1 && <span>{job.openings} openings</span>}
                                                </div>
                                            </div>
                                            <Badge variant={statusVariant[job.status] || 'outline'} className="capitalize shrink-0">{job.status}</Badge>
                                        </div>

                                        {job.site && (
                                            <div className="flex items-center gap-1 text-xs text-muted-foreground mb-2">
                                                <MapPin className="h-3 w-3" />{job.site.name}
                                            </div>
                                        )}

                                        {/* Mini Pipeline Bar */}
                                        {total > 0 && (
                                            <div className="mb-3">
                                                <div className="flex h-2 rounded-full overflow-hidden bg-muted/30">
                                                    {activePct > 0 && <div className="bg-blue-500 transition-all" style={{ width: `${activePct}%` }} />}
                                                    {stalePct > 0 && <div className="bg-amber-500 transition-all" style={{ width: `${stalePct}%` }} />}
                                                    {hiredPct > 0 && <div className="bg-green-500 transition-all" style={{ width: `${hiredPct}%` }} />}
                                                </div>
                                                <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
                                                    <span>{job.metrics.active_candidates} active</span>
                                                    <span>{job.metrics.stale_candidates} stale</span>
                                                    <span>{job.metrics.hired_candidates} hired</span>
                                                </div>
                                            </div>
                                        )}

                                        <div className="flex items-center justify-between text-xs text-muted-foreground mb-3">
                                            <span>{job.metrics.total_applications} applications</span>
                                            <span>{job.metrics.conversion_rate}% conversion</span>
                                        </div>

                                        {/* Hiring Manager */}
                                        {job.hiring_manager && (
                                            <div className="flex items-center gap-2 mb-3">
                                                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">
                                                    {getInitials(job.hiring_manager.name)}
                                                </div>
                                                <span className="text-xs text-muted-foreground">{job.hiring_manager.name}</span>
                                            </div>
                                        )}

                                        {/* Channels */}
                                        {job.posting_channels.length > 0 && (
                                            <div className="flex flex-wrap gap-1 mb-3">
                                                {job.posting_channels.map((ch) => (
                                                    <span key={ch} className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[10px]">
                                                        {channelIcons[ch] ?? '📌'} {ch.replace('_', ' ')}
                                                    </span>
                                                ))}
                                            </div>
                                        )}

                                        {/* Actions */}
                                        <div className="flex items-center gap-1.5 pt-3 border-t">
                                            {can.manage && (
                                                <>
                                                    <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => startEdit(job)}>
                                                        <Edit className="mr-1 h-3 w-3" />Edit
                                                    </Button>
                                                    {job.status !== 'published' && (
                                                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => publishJob(job.id)}>Publish</Button>
                                                    )}
                                                    {job.status === 'published' && (
                                                        <>
                                                            <Button size="sm" variant="secondary" className="h-7 text-xs" onClick={() => closeJob(job.id)}>Close</Button>
                                                            <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => syncPosting(job.id)}>Sync</Button>
                                                        </>
                                                    )}
                                                </>
                                            )}
                                            <Button size="sm" variant="ghost" className="h-7 text-xs ml-auto" asChild>
                                                <Link href={`/careers/jobs/${job.slug}/apply`} target="_blank">Preview</Link>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Title</th>
                                        <th className="px-4 py-3 text-left font-medium">Status</th>
                                        <th className="px-4 py-3 text-left font-medium">Site</th>
                                        <th className="px-4 py-3 text-left font-medium">Manager</th>
                                        <th className="px-4 py-3 text-left font-medium">Pipeline</th>
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {jobs.data.map((job) => (
                                        <tr key={job.id} className="hover:bg-muted/40">
                                            <td className="px-4 py-3">
                                                <p className="font-medium">{job.title}</p>
                                                <p className="text-xs text-muted-foreground capitalize">{job.employment_type.replace('_', ' ')} {job.openings > 1 ? `(${job.openings})` : ''}</p>
                                            </td>
                                            <td className="px-4 py-3"><Badge variant={statusVariant[job.status] || 'outline'} className="capitalize">{job.status}</Badge></td>
                                            <td className="px-4 py-3 text-muted-foreground text-xs">{job.site?.name || '-'}</td>
                                            <td className="px-4 py-3 text-muted-foreground text-xs">{job.hiring_manager?.name || 'Unassigned'}</td>
                                            <td className="px-4 py-3 text-xs">
                                                <span className="text-blue-400">{job.metrics.active_candidates} active</span>
                                                <span className="text-muted-foreground"> / </span>
                                                <span className="text-green-400">{job.metrics.hired_candidates} hired</span>
                                                <span className="text-muted-foreground"> ({job.metrics.conversion_rate}%)</span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-1">
                                                    {can.manage && <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => startEdit(job)}>Edit</Button>}
                                                    {job.status !== 'published' && can.manage && <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => publishJob(job.id)}>Publish</Button>}
                                                    {job.status === 'published' && can.manage && <Button size="sm" variant="secondary" className="h-7 text-xs" onClick={() => closeJob(job.id)}>Close</Button>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {/* Manager Workload */}
                {managerSummary.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-base">Hiring Manager Workload</CardTitle></CardHeader>
                        <CardContent>
                            <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                                {managerSummary.map((entry, i) => (
                                    <div key={i} className="rounded-lg border p-3 hover:bg-muted/30 transition-colors">
                                        <div className="flex items-center gap-2 mb-2">
                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                                {entry.manager ? getInitials(entry.manager.name) : '?'}
                                            </div>
                                            <span className="font-medium text-sm">{entry.manager?.name || 'Unassigned'}</span>
                                        </div>
                                        <div className="grid grid-cols-4 gap-2 text-center">
                                            <div><p className="text-lg font-bold">{entry.open_jobs}</p><p className="text-[10px] text-muted-foreground">Jobs</p></div>
                                            <div><p className="text-lg font-bold text-blue-400">{entry.active_candidates}</p><p className="text-[10px] text-muted-foreground">Active</p></div>
                                            <div><p className="text-lg font-bold text-amber-400">{entry.stale_candidates}</p><p className="text-[10px] text-muted-foreground">Stale</p></div>
                                            <div><p className="text-lg font-bold text-green-400">{entry.hired_candidates}</p><p className="text-[10px] text-muted-foreground">Hired</p></div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
