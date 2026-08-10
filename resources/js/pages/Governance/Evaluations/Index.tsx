import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus, Star } from 'lucide-react';

interface Evaluation {
    id: number;
    title: string;
    evaluation_type: string;
    status: string;
    period_start: string;
    period_end: string;
    due_date: string;
    responses_count: number;
}

interface Props extends PageProps {
    evaluations: {
        data: Evaluation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}

export default function EvaluationsIndex({ auth, evaluations }: Props) {
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getTypeLabel = (type: string) =>
        ({
            board: 'Full Board',
            committee: 'Committee',
            chair: 'Chair',
            individual: 'Individual',
        })[type] || type;

    const activeCount = evaluations.data.filter(
        (e) => e.status === 'active',
    ).length;
    const closedCount = evaluations.data.filter(
        (e) => e.status === 'closed',
    ).length;

    return (
        <AppLayout>
            <Head title="Board Evaluations" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Star}
                        title="Board Evaluations"
                        description="Board and committee performance evaluations"
                        stats={[
                            { label: 'Total', value: evaluations.data.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Closed', value: closedCount },
                        ]}
                        actions={
                            <Link href="/governance/evaluations/create">
                                <Button size="sm">
                                    <Plus className="mr-2 h-4 w-4" /> New
                                    Evaluation
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <div className="grid gap-4">
                    {evaluations.data.map((evaluation) => (
                        <Card key={evaluation.id}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <div className="flex items-center gap-3">
                                            <Star className="h-5 w-5 text-status-warning" />
                                            <Link
                                                href={`/governance/evaluations/${evaluation.id}`}
                                                className="text-lg font-medium hover:text-status-info"
                                            >
                                                {evaluation.title}
                                            </Link>
                                            <Badge variant="outline">
                                                {getTypeLabel(
                                                    evaluation.evaluation_type,
                                                )}
                                            </Badge>
                                            <Badge
                                                className={cn(
                                                    'text-xs',
                                                    getStatusColor(
                                                        evaluation.status,
                                                    ),
                                                )}
                                            >
                                                {evaluation.status}
                                            </Badge>
                                        </div>
                                        <div className="mt-2 flex gap-4 text-sm text-muted-foreground">
                                            <span>
                                                Period:{' '}
                                                {new Date(
                                                    evaluation.period_start,
                                                ).toLocaleDateString(
                                                    'en-NZ',
                                                )}{' '}
                                                -{' '}
                                                {new Date(
                                                    evaluation.period_end,
                                                ).toLocaleDateString('en-NZ')}
                                            </span>
                                            <span>
                                                {evaluation.responses_count}{' '}
                                                response(s)
                                            </span>
                                            <span>
                                                Due:{' '}
                                                {new Date(
                                                    evaluation.due_date,
                                                ).toLocaleDateString('en-NZ')}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {evaluations.data.length === 0 && (
                        <EmptyList
                            icon={Star}
                            itemName="evaluation"
                            createHref="/governance/evaluations/create"
                            createLabel="Start evaluation"
                            variant="compact"
                        />
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
