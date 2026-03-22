import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Globe, XCircle, Pencil, Users } from 'lucide-react';

type Props = {
    posting: {
        id: number;
        title: string;
        department: string | null;
        location: string | null;
        employment_type: string;
        description: string;
        requirements: string | null;
        salary_range_min: number | null;
        salary_range_max: number | null;
        show_salary: boolean;
        salary_range: string | null;
        status: string;
        published_at: string | null;
        closes_at: string | null;
        applications_count: number;
        position: { id: number; title: string } | null;
        created_by: string | null;
        created_at: string;
    };
    can: { manage: boolean };
};

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

export default function JobPostingShow({ posting, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: posting.title, href: `/hr/job-postings/${posting.id}` },
    ];

    const config = statusConfig[posting.status] || statusConfig.draft;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={posting.title} />
            <div className="flex flex-col gap-6 p-6 max-w-3xl mx-auto">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="sm" onClick={() => window.history.back()}>
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <div>
                            <h1 className="text-2xl font-bold">{posting.title}</h1>
                            <div className="mt-1 flex items-center gap-2">
                                <Badge variant="outline" className={config.className}>{config.label}</Badge>
                                <Badge variant="secondary">{typeLabels[posting.employment_type] || posting.employment_type}</Badge>
                            </div>
                        </div>
                    </div>
                    {can.manage && (
                        <div className="flex gap-2">
                            {posting.status === 'draft' && (
                                <Button size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/publish`)}>
                                    <Globe className="mr-1.5 h-4 w-4" />
                                    Publish
                                </Button>
                            )}
                            {posting.status === 'published' && (
                                <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/close`)}>
                                    <XCircle className="mr-1.5 h-4 w-4" />
                                    Close
                                </Button>
                            )}
                            <Button variant="outline" size="sm" onClick={() => router.get(`/hr/job-postings/${posting.id}/edit`)}>
                                <Pencil className="mr-1.5 h-4 w-4" />
                                Edit
                            </Button>
                        </div>
                    )}
                </div>

                {/* Summary */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-2">
                                <Users className="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p className="text-2xl font-bold">{posting.applications_count}</p>
                                    <p className="text-sm text-muted-foreground">Applications</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    {posting.department && (
                        <Card>
                            <CardContent className="pt-6">
                                <p className="text-sm text-muted-foreground">Department</p>
                                <p className="font-semibold">{posting.department}</p>
                            </CardContent>
                        </Card>
                    )}
                    {posting.location && (
                        <Card>
                            <CardContent className="pt-6">
                                <p className="text-sm text-muted-foreground">Location</p>
                                <p className="font-semibold">{posting.location}</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Details */}
                <Card>
                    <CardHeader>
                        <CardTitle>Description</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                            {posting.description}
                        </div>
                    </CardContent>
                </Card>

                {posting.requirements && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Requirements</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                                {posting.requirements}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Metadata */}
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            {posting.salary_range && (
                                <>
                                    <dt className="text-muted-foreground">Salary Range</dt>
                                    <dd>{posting.salary_range}</dd>
                                </>
                            )}
                            {posting.position && (
                                <>
                                    <dt className="text-muted-foreground">Linked Position</dt>
                                    <dd>{posting.position.title}</dd>
                                </>
                            )}
                            {posting.published_at && (
                                <>
                                    <dt className="text-muted-foreground">Published</dt>
                                    <dd>{posting.published_at}</dd>
                                </>
                            )}
                            {posting.closes_at && (
                                <>
                                    <dt className="text-muted-foreground">Closing Date</dt>
                                    <dd>{posting.closes_at}</dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Created By</dt>
                            <dd>{posting.created_by || 'N/A'}</dd>
                            <dt className="text-muted-foreground">Created</dt>
                            <dd>{posting.created_at}</dd>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
