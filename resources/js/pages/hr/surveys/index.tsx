import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, ClipboardList, Eye, Plus } from 'lucide-react';

type Survey = {
    id: number;
    title: string;
    survey_type: string;
    status: string;
    is_anonymous: boolean;
    starts_at: string | null;
    ends_at: string | null;
    responses_count: number;
    created_by: string | null;
    created_at: string;
};

type Props = {
    surveys: {
        data: Survey[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: string | null };
    can: { create: boolean; manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Surveys', href: '/hr/surveys' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
    active: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Active',
    },
    closed: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Closed',
    },
};

const typeLabels: Record<string, string> = {
    pulse: 'Pulse',
    enps: 'eNPS',
    engagement: 'Engagement',
    custom: 'Custom',
};

export default function SurveyIndex({ surveys, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/surveys',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const totalResponses = surveys.data.reduce(
        (sum, s) => sum + (s.responses_count || 0),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Surveys" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardList}
                        title="Employee Surveys"
                        description="Create and manage satisfaction surveys"
                        stats={[
                            { label: 'Total', value: surveys.data.length },
                            {
                                label: 'Active',
                                value: surveys.data.filter((s) => s.status === 'active').length,
                            },
                            { label: 'Responses', value: totalResponses },
                        ]}
                        actions={
                            can.create && (
                                <Button asChild size="sm">
                                    <Link href="/hr/surveys/create">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Survey
                                    </Link>
                                </Button>
                            )
                        }
                    />
                }
            >
                {/* Status Filter */}
                <div className="flex gap-2">
                    {['all', 'draft', 'active', 'closed'].map((s) => (
                        <Button
                            key={s}
                            variant={
                                (!filters.status && s === 'all') ||
                                filters.status === s
                                    ? 'default'
                                    : 'outline'
                            }
                            size="sm"
                            onClick={() =>
                                onFilter({ status: s === 'all' ? null : s })
                            }
                        >
                            <span className="capitalize">{s}</span>
                        </Button>
                    ))}
                </div>

                {/* Survey List */}
                <div className="grid gap-4">
                    {surveys.data.map((survey) => {
                        const config =
                            statusConfig[survey.status] || statusConfig.draft;
                        return (
                            <Card key={survey.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <CardTitle className="text-base">
                                                {survey.title}
                                            </CardTitle>
                                            <div className="mt-1 flex items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                                <Badge variant="secondary">
                                                    {typeLabels[
                                                        survey.survey_type
                                                    ] || survey.survey_type}
                                                </Badge>
                                                {survey.is_anonymous && (
                                                    <Badge variant="secondary">
                                                        Anonymous
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            {survey.status === 'active' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/hr/surveys/${survey.id}/respond`}
                                                    >
                                                        <BarChart3 className="mr-1.5 h-3.5 w-3.5" />
                                                        Respond
                                                    </Link>
                                                </Button>
                                            )}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/hr/surveys/${survey.id}`}
                                                >
                                                    <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                    Results
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="flex gap-6 text-sm text-muted-foreground">
                                        <span>
                                            {survey.responses_count} responses
                                        </span>
                                        {survey.starts_at && (
                                            <span>
                                                Starts: {survey.starts_at}
                                            </span>
                                        )}
                                        {survey.ends_at && (
                                            <span>Ends: {survey.ends_at}</span>
                                        )}
                                        {survey.created_by && (
                                            <span>By: {survey.created_by}</span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {surveys.data.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No surveys found. Create your first survey to
                                get started.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                {surveys.links?.length > 3 && (
                    <LaravelPagination links={surveys.links} />
                )}
            </PageLayout>
        </AppLayout>
    );
}
