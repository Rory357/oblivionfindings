import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

type Props = {
    tasks: { data: any[]; links: any[] };
};

const priorityColors: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-status-info-bg text-status-info',
    high: 'bg-status-warning-bg text-status-warning',
    urgent: 'bg-status-critical-bg text-status-critical',
};

export default function OverdueTasks({ tasks }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Tasks', href: '/respite/tasks' },
                { title: 'Overdue', href: '/respite/tasks/overdue' },
            ]}
        >
            <Head title="Overdue Tasks" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Overdue Tasks"
                        description="Tasks that have passed their due date."
                        stats={[{ label: 'Overdue', value: tasks.data.length }]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {tasks.data.map((t: any) => (
                        <Card key={t.id} className="border-status-critical/30">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {t.title}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge
                                                    className={
                                                        priorityColors[
                                                            t.priority
                                                        ] || ''
                                                    }
                                                >
                                                    {t.priority}
                                                </Badge>
                                                <Badge className="bg-status-critical-bg text-status-critical">
                                                    Overdue
                                                </Badge>
                                                <Badge variant="outline">
                                                    {t.status?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            {t.assigned_to && (
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    Assigned to:{' '}
                                                    {t.assigned_to?.name}
                                                </div>
                                            )}
                                            {t.due_at && (
                                                <div className="mt-1 text-xs font-medium text-status-critical">
                                                    Due:{' '}
                                                    {formatDateTimeLong(
                                                        t.due_at,
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                        <Link
                                            href={`/respite/tasks/${t.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!tasks.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No overdue tasks.
                        </div>
                    )}
                </div>

                {tasks?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {tasks.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                onClick={() =>
                                    l.url &&
                                    router.get(
                                        l.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
