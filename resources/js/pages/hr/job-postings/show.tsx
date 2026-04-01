import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowLeft, Globe, XCircle, Pencil, Users, Eye, Copy, BarChart3,
    CheckCircle2, Clock, AlertCircle, ExternalLink, Lock, Wifi, Mail, FileText,
} from 'lucide-react';
import { statusConfig, employmentTypeLabels, stageLabels } from '@/lib/job-posting-constants';
import type { JobPostingDetail, RecentApplication } from '@/types/job-postings';

type Props = {
    posting: JobPostingDetail;
    recentApplications: RecentApplication[];
    analytics: {
        views: number;
        applications: number;
        conversion_rate: number;
        days_published: number;
    };
    can: { manage: boolean };
};

export default function JobPostingShow({ posting, recentApplications, analytics, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: posting.title, href: '#' },
    ];

    const config = statusConfig[posting.status] || statusConfig.draft;
    const publicUrl = posting.slug ? `${window.location.origin}/careers/${posting.slug}` : null;

    const copyPublicLink = () => {
        if (publicUrl) navigator.clipboard.writeText(publicUrl);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={posting.title} />
            <div className="flex flex-col gap-6 p-6 max-w-4xl mx-auto">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="sm" onClick={() => router.visit('/hr/job-postings')}>
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <div>
                            <h1 className="text-2xl font-bold">{posting.title}</h1>
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className={config.className}>{config.label}</Badge>
                                <Badge variant="secondary">{employmentTypeLabels[posting.employment_type] || posting.employment_type}</Badge>
                                {posting.is_remote && <Badge variant="outline" className="gap-1 border-blue-500/30 text-blue-400 bg-blue-500/10"><Wifi className="h-3 w-3" /> Remote</Badge>}
                                {posting.is_internal && <Badge variant="outline" className="gap-1 border-purple-500/30 text-purple-400 bg-purple-500/10"><Lock className="h-3 w-3" /> Internal</Badge>}
                            </div>
                        </div>
                    </div>
                    {can.manage && (
                        <div className="flex gap-2 shrink-0 flex-wrap justify-end">
                            {posting.status === 'draft' && (
                                <Button size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/publish`)}>
                                    <Globe className="mr-1.5 h-4 w-4" /> Publish
                                </Button>
                            )}
                            {posting.status === 'pending_approval' && (
                                <>
                                    <Button size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/approve`)}>
                                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Approve
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/reject-approval`)}>
                                        <XCircle className="mr-1.5 h-4 w-4" /> Reject
                                    </Button>
                                </>
                            )}
                            {posting.status === 'published' && (
                                <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/close`)}>
                                    <XCircle className="mr-1.5 h-4 w-4" /> Close
                                </Button>
                            )}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/hr/job-postings/${posting.id}/preview`}><Eye className="mr-1.5 h-4 w-4" /> Preview</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/hr/job-postings/${posting.id}/edit`}><Pencil className="mr-1.5 h-4 w-4" /> Edit</Link>
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/duplicate`)}>
                                <Copy className="mr-1.5 h-4 w-4" /> Duplicate
                            </Button>
                        </div>
                    )}
                </div>

                {/* Public URL */}
                {publicUrl && posting.status === 'published' && (
                    <div className="flex items-center gap-2 p-3 bg-emerald-500/5 border border-emerald-500/20 rounded-lg">
                        <Globe className="h-4 w-4 text-emerald-500 shrink-0" />
                        <code className="text-sm flex-1 truncate text-emerald-400">{publicUrl}</code>
                        <Button variant="ghost" size="sm" onClick={copyPublicLink}><Copy className="h-3.5 w-3.5" /></Button>
                        <Button variant="ghost" size="sm" asChild>
                            <a href={publicUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="h-3.5 w-3.5" /></a>
                        </Button>
                    </div>
                )}

                {/* Analytics KPIs */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-blue-500/10 p-2"><Eye className="h-4 w-4 text-blue-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{analytics.views}</p>
                                    <p className="text-xs text-muted-foreground">Views</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-primary/10 p-2"><Users className="h-4 w-4 text-primary" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{analytics.applications}</p>
                                    <p className="text-xs text-muted-foreground">Applications</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-500/10 p-2"><BarChart3 className="h-4 w-4 text-emerald-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{analytics.conversion_rate}%</p>
                                    <p className="text-xs text-muted-foreground">Conversion</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-amber-500/10 p-2"><Clock className="h-4 w-4 text-amber-500" /></div>
                                <div>
                                    <p className="text-2xl font-bold">{analytics.days_published}</p>
                                    <p className="text-xs text-muted-foreground">Days Published</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Description */}
                <Card>
                    <CardHeader><CardTitle>Description</CardTitle></CardHeader>
                    <CardContent>
                        {posting.summary && <p className="text-muted-foreground text-sm mb-4 italic">{posting.summary}</p>}
                        <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.description}</div>
                    </CardContent>
                </Card>

                {posting.responsibilities && (
                    <Card>
                        <CardHeader><CardTitle>Responsibilities</CardTitle></CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.responsibilities}</div>
                        </CardContent>
                    </Card>
                )}

                {posting.requirements && (
                    <Card>
                        <CardHeader><CardTitle>Requirements</CardTitle></CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.requirements}</div>
                        </CardContent>
                    </Card>
                )}

                {/* Screening Questions */}
                {posting.screening_questions.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle>Screening Questions</CardTitle></CardHeader>
                        <CardContent>
                            <ol className="space-y-2">
                                {posting.screening_questions.map((q, i) => (
                                    <li key={q.id} className="flex items-start gap-2 text-sm">
                                        <span className="text-muted-foreground shrink-0">{i + 1}.</span>
                                        <span>{q.question}</span>
                                        {q.required && <Badge variant="outline" className="text-xs shrink-0">Required</Badge>}
                                        <Badge variant="secondary" className="text-xs shrink-0">{q.type.replace('_', '/')}</Badge>
                                    </li>
                                ))}
                            </ol>
                        </CardContent>
                    </Card>
                )}

                {/* Recent Applications */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle>Recent Applications</CardTitle>
                            {recentApplications.length > 0 && (
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href="/hr/recruitment">View All in Recruitment</Link>
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {recentApplications.length === 0 ? (
                            <p className="text-sm text-muted-foreground text-center py-4">No applications yet.</p>
                        ) : (
                            <div className="space-y-2">
                                {recentApplications.map(app => (
                                    <div key={app.id} className="flex items-center justify-between p-3 rounded-lg bg-muted/30">
                                        <div>
                                            <p className="text-sm font-medium">{app.candidate_name}</p>
                                            <p className="text-xs text-muted-foreground">{app.candidate_email}</p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="secondary" className="text-xs">
                                                {stageLabels[app.candidate_stage || app.status] || app.status}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">{app.applied_at}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Notification & Approval Settings */}
                <Card>
                    <CardHeader><CardTitle>Settings & Notifications</CardTitle></CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-muted-foreground flex items-center gap-1"><Users className="h-3.5 w-3.5" /> Hiring Manager</dt>
                                <dd className="mt-1 font-medium">{posting.hiring_manager?.name || 'Not assigned'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground flex items-center gap-1"><Mail className="h-3.5 w-3.5" /> Notification Emails</dt>
                                <dd className="mt-1">
                                    {posting.notification_emails.length > 0 ? (
                                        <div className="flex flex-wrap gap-1">
                                            {posting.notification_emails.map(e => (
                                                <Badge key={e} variant="secondary" className="text-xs">{e}</Badge>
                                            ))}
                                        </div>
                                    ) : <span className="text-muted-foreground">None configured</span>}
                                </dd>
                            </div>
                            {posting.requires_approval && (
                                <div>
                                    <dt className="text-muted-foreground flex items-center gap-1"><CheckCircle2 className="h-3.5 w-3.5" /> Approval</dt>
                                    <dd className="mt-1 font-medium">
                                        {posting.approved_by ? `Approved by ${posting.approved_by} on ${posting.approved_at}` : 'Requires approval'}
                                    </dd>
                                </div>
                            )}
                            {posting.salary_range && (
                                <div>
                                    <dt className="text-muted-foreground">Salary Range</dt>
                                    <dd className="mt-1 font-medium">{posting.salary_range}</dd>
                                </div>
                            )}
                            {posting.position && (
                                <div>
                                    <dt className="text-muted-foreground">Linked Position</dt>
                                    <dd className="mt-1 font-medium">{posting.position.title}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">Created</dt>
                                <dd className="mt-1">{posting.created_at} by {posting.created_by || 'N/A'}</dd>
                            </div>
                            {posting.published_at && (
                                <div>
                                    <dt className="text-muted-foreground">Published</dt>
                                    <dd className="mt-1">{posting.published_at}</dd>
                                </div>
                            )}
                            {posting.closes_at && (
                                <div>
                                    <dt className="text-muted-foreground">Closing Date</dt>
                                    <dd className="mt-1">{posting.closes_at}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
