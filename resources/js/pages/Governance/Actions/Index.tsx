import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { show as showAction } from '@/routes/governance/actions';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { CheckSquare, User } from 'lucide-react';

interface ActionItem {
    id: number;
    action_reference: string;
    description: string;
    due_date: string;
    status: string;
    priority: string;
    assigned_to: { name: string };
    source_type: string;
}

interface Props extends PageProps {
    items: {
        data: ActionItem[];
    };
    summary: {
        total_open: number;
        overdue: number;
        my_open: number;
        high_priority: number;
    };
}

export default function ActionsIndex({ auth, items, summary }: Props) {
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getPriorityColor = (priority: string) => {
        return (
            {
                low: 'bg-muted text-foreground',
                medium: 'bg-status-info-bg text-status-info',
                high: 'bg-status-warning-bg text-status-warning',
                critical: 'bg-status-critical-bg text-status-critical',
            }[priority] || 'bg-muted text-foreground'
        );
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        const days = Math.ceil(
            (date.getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24),
        );

        if (days < 0)
            return {
                text: `${Math.abs(days)} days overdue`,
                color: 'text-status-critical',
            };
        if (days === 0)
            return { text: 'Due today', color: 'text-status-warning' };
        return {
            text: `${days} days left`,
            color: days <= 3 ? 'text-status-warning' : 'text-muted-foreground',
        };
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Actions', href: '/governance/actions' },
            ]}
        >
            <Head title="Actions" />

            <PageLayout
                hero={
                    <PageHero
                        icon={CheckSquare}
                        title="Actions"
                        description="Track board decisions and follow-ups through to completion."
                        stats={[
                            { label: 'Open', value: summary.total_open },
                            { label: 'Overdue', value: summary.overdue },
                            { label: 'My open', value: summary.my_open },
                            {
                                label: 'High priority',
                                value: summary.high_priority,
                            },
                        ]}
                    />
                }
            >
                {/* List */}
                <Card dusk="actions-list-card">
                    <CardHeader>
                        <CardTitle>Action Items</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {items.data.length === 0 ? (
                                <p className="py-8 text-center text-muted-foreground">
                                    No actions assigned or outstanding.
                                </p>
                            ) : (
                                items.data.map((item) => {
                                    const dateInfo = formatDate(item.due_date);
                                    return (
                                        <div
                                            key={item.id}
                                            className={cn(
                                                'flex items-start justify-between rounded-lg border p-4',
                                                dateInfo.text.includes(
                                                    'overdue',
                                                ) &&
                                                    'border-status-critical/30 bg-status-critical-bg',
                                            )}
                                        >
                                            <div className="flex-1">
                                                <div className="mb-1 flex items-center gap-2">
                                                    <span className="text-sm text-muted-foreground">
                                                        {item.action_reference}
                                                    </span>
                                                    <Badge
                                                        className={cn(
                                                            getPriorityColor(
                                                                item.priority,
                                                            ),
                                                        )}
                                                    >
                                                        {item.priority}
                                                    </Badge>
                                                </div>
                                                <p className="font-medium">
                                                    {item.description}
                                                </p>
                                                <div className="mt-2 flex items-center gap-4 text-sm text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {item.assigned_to.name}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            dateInfo.color,
                                                        )}
                                                    >
                                                        {dateInfo.text}
                                                    </span>
                                                </div>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={showAction.url({
                                                        action: item.id,
                                                    })}
                                                >
                                                    View &rarr;
                                                </Link>
                                            </Button>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
