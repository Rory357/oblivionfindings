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
import { AlertCircle, AlertTriangle, BriefcaseBusiness, CheckCircle2, Clock3, Globe2, UserCheck } from 'lucide-react';

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

const statusVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    published: 'default',
    draft: 'secondary',
    paused: 'outline',
    closed: 'secondary',
};

export default function RecruitmentJobs({
    jobs,
    summary,
    managerSummary,
    sites,
    interviewKits,
    hiringManagers,
    statuses,
    employmentTypes,
    postingChannels,
    filters,
    can,
}: Props) {
    const [editingJobId, setEditingJobId] = useState<number | null>(null);
    const createForm = useForm({
        title: '',
        position_role: '',
        site_id: '',
        employment_type: employmentTypes[0] || 'full_time',
        openings: '1',
        summary: '',
        description: '',
        requirements: '',
        responsibilities: '',
        default_interview_kit_id: '',
        hiring_manager_user_id: '',
        posting_channels: [] as string[],
        closing_at: '',
    });

    const summaryCards = useMemo(() => ([
        { label: 'Open Requisitions', value: summary.open_requisitions, icon: BriefcaseBusiness },
        { label: 'Active Candidates', value: summary.active_candidates, icon: UserCheck },
        { label: 'Stale Candidates', value: summary.stale_candidates, icon: AlertTriangle },
        { label: 'Offers In Flight', value: summary.offers_in_flight, icon: Clock3 },
        { label: 'Hired', value: summary.hired_candidates, icon: CheckCircle2 },
        { label: 'Externally Posted', value: summary.externally_posted_jobs, icon: Globe2 },
        { label: 'Posting Errors', value: summary.external_sync_failed_jobs, icon: AlertCircle },
    ]), [summary]);

    function resetJobForm() {
        createForm.reset();
        setEditingJobId(null);
    }

    function submitJobForm(e: React.FormEvent) {
        e.preventDefault();

        if (editingJobId) {
            createForm.put(`/hr/recruitment/jobs/${editingJobId}`, {
                preserveScroll: true,
                onSuccess: () => resetJobForm(),
            });

            return;
        }

        createForm.post('/hr/recruitment/jobs', {
            preserveScroll: true,
            onSuccess: () => resetJobForm(),
        });
    }

    function applyFilter(key: string, value: string | null) {
        router.get('/hr/recruitment/jobs', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    function publishJob(jobId: number) {
        router.post(`/hr/recruitment/jobs/${jobId}/publish`, {}, { preserveScroll: true });
    }

    function closeJob(jobId: number) {
        router.post(`/hr/recruitment/jobs/${jobId}/close`, {}, { preserveScroll: true });
    }

    function syncPosting(jobId: number) {
        router.post(`/hr/recruitment/jobs/${jobId}/sync-posting`, {}, { preserveScroll: true });
    }

    function unpublishPosting(jobId: number) {
        router.post(`/hr/recruitment/jobs/${jobId}/unpublish-posting`, {}, { preserveScroll: true });
    }

    function togglePostingChannel(channel: string, checked: boolean) {
        createForm.setData('posting_channels', checked
            ? Array.from(new Set([...createForm.data.posting_channels, channel]))
            : createForm.data.posting_channels.filter((value) => value !== channel));
    }

    function startEdit(job: Job) {
        setEditingJobId(job.id);
        createForm.setData({
            title: job.title || '',
            position_role: job.position_role || '',
            site_id: job.site ? String(job.site.id) : '',
            employment_type: job.employment_type || (employmentTypes[0] || 'full_time'),
            openings: String(job.openings || 1),
            summary: job.summary || '',
            description: job.description || '',
            requirements: job.requirements || '',
            responsibilities: job.responsibilities || '',
            default_interview_kit_id: job.default_interview_kit ? String(job.default_interview_kit.id) : '',
            hiring_manager_user_id: job.hiring_manager ? String(job.hiring_manager.id) : '',
            posting_channels: job.posting_channels || [],
            closing_at: job.closing_at || '',
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: 'Jobs', href: '/hr/recruitment/jobs' },
            ]}
        >
            <Head title="Recruitment Jobs" />
            <PageShell>
                <PageHeader
                    title="Job Requisitions"
                    description="Create and publish roles to the public careers page."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/careers" target="_blank">Public Careers Page</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/hr/recruitment/kits">Interview Kits</Link>
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-7">
                    {summaryCards.map((card) => {
                        const Icon = card.icon;
                        return (
                            <Card key={card.label}>
                                <CardContent className="flex items-center gap-3 py-4">
                                    <div className="rounded-md bg-muted p-2">
                                        <Icon className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p className="text-xl font-semibold">{card.value}</p>
                                        <p className="text-xs text-muted-foreground">{card.label}</p>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>{editingJobId ? 'Edit Job Requisition' : 'Create Job Requisition'}</CardTitle>
                                {editingJobId && (
                                    <Button type="button" size="sm" variant="outline" onClick={resetJobForm}>
                                        Cancel Edit
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitJobForm} className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Title</Label>
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
                                        <Label>Employment Type</Label>
                                        <Select value={createForm.data.employment_type} onValueChange={(v) => createForm.setData('employment_type', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {employmentTypes.map((type) => (
                                                    <SelectItem key={type} value={type}>{type.replace('_', ' ')}</SelectItem>
                                                ))}
                                            </SelectContent>
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
                                                {sites.map((site) => (
                                                    <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Default Interview Kit</Label>
                                        <Select value={createForm.data.default_interview_kit_id || '__none__'} onValueChange={(v) => createForm.setData('default_interview_kit_id', v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">None</SelectItem>
                                                {interviewKits.map((kit) => (
                                                    <SelectItem key={kit.id} value={String(kit.id)}>{kit.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Hiring Manager</Label>
                                        <Select value={createForm.data.hiring_manager_user_id || '__none__'} onValueChange={(v) => createForm.setData('hiring_manager_user_id', v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">None</SelectItem>
                                                {hiringManagers.map((manager) => (
                                                    <SelectItem key={manager.id} value={String(manager.id)}>{manager.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>External Posting Channels</Label>
                                    <div className="flex flex-wrap gap-3 rounded-md border p-3">
                                        {postingChannels.map((channel) => (
                                            <label key={channel} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={createForm.data.posting_channels.includes(channel)}
                                                    onChange={(event) => togglePostingChannel(channel, event.target.checked)}
                                                />
                                                <span className="capitalize">{channel.replace('_', ' ')}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {createForm.errors.posting_channels && (
                                        <p className="text-sm text-destructive">{createForm.errors.posting_channels}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label>Summary</Label>
                                    <Textarea rows={2} value={createForm.data.summary} onChange={(e) => createForm.setData('summary', e.target.value)} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Description</Label>
                                    <Textarea rows={4} value={createForm.data.description} onChange={(e) => createForm.setData('description', e.target.value)} />
                                    {createForm.errors.description && <p className="text-sm text-destructive">{createForm.errors.description}</p>}
                                </div>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Responsibilities</Label>
                                        <Textarea rows={3} value={createForm.data.responsibilities} onChange={(e) => createForm.setData('responsibilities', e.target.value)} />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Requirements</Label>
                                        <Textarea rows={3} value={createForm.data.requirements} onChange={(e) => createForm.setData('requirements', e.target.value)} />
                                    </div>
                                </div>

                                <Button type="submit" disabled={createForm.processing}>
                                    {createForm.processing ? 'Saving...' : editingJobId ? 'Update Job' : 'Create Draft Job'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        className="w-64"
                        placeholder="Search job titles..."
                        defaultValue={filters.search}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                applyFilter('search', (e.target as HTMLInputElement).value);
                            }
                        }}
                    />
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All statuses</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>{status}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.hiring_manager_user_id || '__all__'}
                        onValueChange={(value) => applyFilter('hiring_manager_user_id', value === '__all__' ? null : value)}
                    >
                        <SelectTrigger className="w-64"><SelectValue placeholder="All hiring managers" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All hiring managers</SelectItem>
                            <SelectItem value="unassigned">Unassigned</SelectItem>
                            {hiringManagers.map((manager) => (
                                <SelectItem key={manager.id} value={String(manager.id)}>{manager.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Site</th>
                                    <th className="px-4 py-3 text-left font-medium">Hiring Manager</th>
                                    <th className="px-4 py-3 text-left font-medium">Pipeline</th>
                                    <th className="px-4 py-3 text-left font-medium">Closes</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {jobs.data.map((job) => (
                                    <tr key={job.id} className="hover:bg-muted/40">
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{job.title}</p>
                                            <p className="text-xs text-muted-foreground">
                                                /{job.slug}
                                                {job.position_role ? ` · ${job.position_role}` : ''}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Channels: {job.posting_channels.length > 0 ? job.posting_channels.join(', ') : 'none selected'}
                                            </p>
                                            <div className="mt-1">
                                                <Badge variant={job.external_posting_status === 'posted' ? 'default' : job.external_posting_status === 'sync_failed' ? 'destructive' : 'outline'}>
                                                    {job.external_posting_status.replace('_', ' ')}
                                                </Badge>
                                            </div>
                                            {job.external_sync_error && (
                                                <p className="mt-1 text-xs text-destructive">{job.external_sync_error}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={statusVariant[job.status] || 'outline'} className="capitalize">{job.status}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{job.site?.name || '-'}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{job.hiring_manager?.name || 'Unassigned'}</td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            <div>{job.metrics.active_candidates} active</div>
                                            <div>{job.metrics.stale_candidates} stale</div>
                                            <div>{job.metrics.hired_candidates} hired ({job.metrics.conversion_rate}%)</div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{job.closing_at || '-'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                {can.manage && (
                                                    <Button size="sm" variant="outline" onClick={() => startEdit(job)}>
                                                        Edit
                                                    </Button>
                                                )}
                                                {job.status !== 'published' && can.manage && (
                                                    <Button size="sm" variant="outline" onClick={() => publishJob(job.id)}>Publish</Button>
                                                )}
                                                {job.status === 'published' && can.manage && (
                                                    <Button size="sm" variant="secondary" onClick={() => closeJob(job.id)}>Close</Button>
                                                )}
                                                {job.status === 'published' && can.manage && (
                                                    <Button size="sm" variant="outline" onClick={() => syncPosting(job.id)}>
                                                        Sync Posting
                                                    </Button>
                                                )}
                                                {job.external_posting_status === 'posted' && can.manage && (
                                                    <Button size="sm" variant="ghost" onClick={() => unpublishPosting(job.id)}>
                                                        Unpublish
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="ghost" asChild>
                                                    <Link href={`/careers/jobs/${job.slug}/apply`} target="_blank">Preview</Link>
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {jobs.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">No jobs found.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Hiring Manager Workload</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {managerSummary.length === 0 && (
                            <p className="text-sm text-muted-foreground">No hiring manager workload found for this filter set.</p>
                        )}
                        {managerSummary.map((entry, index) => (
                            <div key={`${entry.manager?.id ?? 'unassigned'}-${index}`} className="flex items-center justify-between rounded-md border p-3">
                                <div>
                                    <p className="text-sm font-medium">{entry.manager?.name || 'Unassigned'}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {entry.open_jobs} open jobs · {entry.active_candidates} active candidates · {entry.stale_candidates} stale
                                    </p>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {entry.offers_in_flight} offers · {entry.hired_candidates} hired
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
