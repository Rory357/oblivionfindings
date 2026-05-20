import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    employmentTypeLabels,
    stageLabels,
    statusConfig,
} from '@/lib/job-posting-constants';
import { type BreadcrumbItem } from '@/types';
import type { JobPostingDetail, RecentApplication } from '@/types/job-postings';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    Eye,
    Globe,
    Lock,
    Mail,
    Pencil,
    Users,
    Wifi,
    XCircle,
} from 'lucide-react';

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

export default function JobPostingShow({
    posting,
    recentApplications,
    analytics,
    can,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: posting.title, href: '#' },
    ];

    const config = statusConfig[posting.status] || statusConfig.draft;
    const publicUrl = posting.slug
        ? `${window.location.origin}/careers/${posting.slug}`
        : null;

    const copyPublicLink = () => {
        if (publicUrl) navigator.clipboard.writeText(publicUrl);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={posting.title} />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/job-postings"
                        title={posting.title}
                        description={
                            <span className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant="outline"
                                    className={config.className}
                                >
                                    {config.label}
                                </Badge>
                                <Badge variant="secondary">
                                    {employmentTypeLabels[
                                        posting.employment_type
                                    ] || posting.employment_type}
                                </Badge>
                                {posting.is_remote && (
                                    <Badge
                                        variant="outline"
                                        className="gap-1 border-status-info/30 bg-status-info text-status-info"
                                    >
                                        <Wifi className="h-3 w-3" /> Remote
                                    </Badge>
                                )}
                                {posting.is_internal && (
                                    <Badge
                                        variant="outline"
                                        className="gap-1 border-primary/30 bg-primary/10 text-primary"
                                    >
                                        <Lock className="h-3 w-3" /> Internal
                                    </Badge>
                                )}
                            </span>
                        }
                        actions={
                            can.manage ? (
                                <>
                                    {posting.status === 'draft' && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/hr/job-postings/${posting.id}/publish`,
                                                )
                                            }
                                        >
                                            <Globe className="mr-1.5 h-4 w-4" />{' '}
                                            Publish
                                        </Button>
                                    )}
                                    {posting.status === 'pending_approval' && (
                                        <>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/hr/job-postings/${posting.id}/approve`,
                                                    )
                                                }
                                            >
                                                <CheckCircle2 className="mr-1.5 h-4 w-4" />{' '}
                                                Approve
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/hr/job-postings/${posting.id}/reject-approval`,
                                                    )
                                                }
                                            >
                                                <XCircle className="mr-1.5 h-4 w-4" />{' '}
                                                Reject
                                            </Button>
                                        </>
                                    )}
                                    {posting.status === 'published' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/hr/job-postings/${posting.id}/close`,
                                                )
                                            }
                                        >
                                            <XCircle className="mr-1.5 h-4 w-4" />{' '}
                                            Close
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/hr/job-postings/${posting.id}/preview`}
                                        >
                                            <Eye className="mr-1.5 h-4 w-4" />{' '}
                                            Preview
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/hr/job-postings/${posting.id}/edit`}
                                        >
                                            <Pencil className="mr-1.5 h-4 w-4" />{' '}
                                            Edit
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/hr/job-postings/${posting.id}/duplicate`,
                                            )
                                        }
                                    >
                                        <Copy className="mr-1.5 h-4 w-4" />{' '}
                                        Duplicate
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                }
            >
                <div className="mx-auto max-w-4xl space-y-6">
                {/* Public URL */}
                {publicUrl && posting.status === 'published' && (
                    <div className="flex items-center gap-2 rounded-lg border border-status-success/20 bg-status-success p-3">
                        <Globe className="h-4 w-4 shrink-0 text-status-success" />
                        <code className="flex-1 truncate text-sm text-status-success">
                            {publicUrl}
                        </code>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={copyPublicLink}
                        >
                            <Copy className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="ghost" size="sm" asChild>
                            <a
                                href={publicUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="h-3.5 w-3.5" />
                            </a>
                        </Button>
                    </div>
                )}

                {/* Analytics KPIs */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info p-2">
                                    <Eye className="h-4 w-4 text-status-info" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {analytics.views}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Views
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <Users className="h-4 w-4 text-primary" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {analytics.applications}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Applications
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success p-2">
                                    <BarChart3 className="h-4 w-4 text-status-success" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {analytics.conversion_rate}%
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Conversion
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2">
                                    <Clock className="h-4 w-4 text-status-warning" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {analytics.days_published}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Days Published
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Description */}
                <Card>
                    <CardHeader>
                        <CardTitle>Description</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {posting.summary && (
                            <p className="mb-4 text-sm text-muted-foreground italic">
                                {posting.summary}
                            </p>
                        )}
                        <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                            {posting.description}
                        </div>
                    </CardContent>
                </Card>

                {posting.responsibilities && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Responsibilities</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                                {posting.responsibilities}
                            </div>
                        </CardContent>
                    </Card>
                )}

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

                {/* Screening Questions */}
                {posting.screening_questions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Screening Questions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ol className="space-y-2">
                                {posting.screening_questions.map((q, i) => (
                                    <li
                                        key={q.id}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <span className="shrink-0 text-muted-foreground">
                                            {i + 1}.
                                        </span>
                                        <span>{q.question}</span>
                                        {q.required && (
                                            <Badge
                                                variant="outline"
                                                className="shrink-0 text-xs"
                                            >
                                                Required
                                            </Badge>
                                        )}
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0 text-xs"
                                        >
                                            {q.type.replace('_', '/')}
                                        </Badge>
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
                                    <Link href="/hr/recruitment">
                                        View All in Recruitment
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {recentApplications.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                No applications yet.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {recentApplications.map((app) => (
                                    <div
                                        key={app.id}
                                        className="flex items-center justify-between rounded-lg bg-muted/30 p-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium">
                                                {app.candidate_name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {app.candidate_email}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant="secondary"
                                                className="text-xs"
                                            >
                                                {stageLabels[
                                                    app.candidate_stage ||
                                                        app.status
                                                ] || app.status}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {app.applied_at}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Notification & Approval Settings */}
                <Card>
                    <CardHeader>
                        <CardTitle>Settings & Notifications</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="flex items-center gap-1 text-muted-foreground">
                                    <Users className="h-3.5 w-3.5" /> Hiring
                                    Manager
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {posting.hiring_manager?.name ||
                                        'Not assigned'}
                                </dd>
                            </div>
                            <div>
                                <dt className="flex items-center gap-1 text-muted-foreground">
                                    <Mail className="h-3.5 w-3.5" />{' '}
                                    Notification Emails
                                </dt>
                                <dd className="mt-1">
                                    {posting.notification_emails.length > 0 ? (
                                        <div className="flex flex-wrap gap-1">
                                            {posting.notification_emails.map(
                                                (e) => (
                                                    <Badge
                                                        key={e}
                                                        variant="secondary"
                                                        className="text-xs"
                                                    >
                                                        {e}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            None configured
                                        </span>
                                    )}
                                </dd>
                            </div>
                            {posting.requires_approval && (
                                <div>
                                    <dt className="flex items-center gap-1 text-muted-foreground">
                                        <CheckCircle2 className="h-3.5 w-3.5" />{' '}
                                        Approval
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {posting.approved_by
                                            ? `Approved by ${posting.approved_by} on ${posting.approved_at}`
                                            : 'Requires approval'}
                                    </dd>
                                </div>
                            )}
                            {posting.salary_range && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Salary Range
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {posting.salary_range}
                                    </dd>
                                </div>
                            )}
                            {posting.position && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Linked Position
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {posting.position.title}
                                    </dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">
                                    Created
                                </dt>
                                <dd className="mt-1">
                                    {posting.created_at} by{' '}
                                    {posting.created_by || 'N/A'}
                                </dd>
                            </div>
                            {posting.published_at && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Published
                                    </dt>
                                    <dd className="mt-1">
                                        {posting.published_at}
                                    </dd>
                                </div>
                            )}
                            {posting.closes_at && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Closing Date
                                    </dt>
                                    <dd className="mt-1">
                                        {posting.closes_at}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
