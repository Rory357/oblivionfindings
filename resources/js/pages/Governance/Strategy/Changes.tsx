import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { History, Info, Pencil, Plus, Trash2 } from 'lucide-react';

interface Change {
    type: 'added' | 'updated' | 'removed';
    goal: string;
    detail: string;
}

interface Props extends PageProps {
    plan: any;
    changes: {
        has_snapshot: boolean;
        changes: Change[];
    };
}

const typeConfig: Record<
    string,
    { label: string; color: string; icon: typeof Plus; badgeClass: string }
> = {
    added: {
        label: 'Added',
        color: 'text-status-success',
        icon: Plus,
        badgeClass:
            'bg-status-success-bg text-status-success border-status-success/30',
    },
    updated: {
        label: 'Updated',
        color: 'text-status-warning',
        icon: Pencil,
        badgeClass:
            'bg-status-warning-bg text-status-warning border-status-warning/30',
    },
    removed: {
        label: 'Removed',
        color: 'text-status-critical',
        icon: Trash2,
        badgeClass:
            'bg-status-critical-bg text-status-critical border-status-critical/30',
    },
};

export default function StrategyChanges({ auth, plan, changes }: Props) {
    const grouped = changes.changes.reduce<Record<string, Change[]>>(
        (acc, c) => {
            (acc[c.type] = acc[c.type] || []).push(c);
            return acc;
        },
        {},
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategy', href: '/governance/strategy' },
                { title: 'Changes', href: '#' },
            ]}
        >
            <Head title="Strategic Plan Changes" />

            <PageLayout
                hero={
                    <PageHero
                        icon={History}
                        title="Strategic Plan Changes"
                        description="Changes since last snapshot."
                        stats={[
                            {
                                label: 'Added',
                                value: grouped.added?.length ?? 0,
                            },
                            {
                                label: 'Updated',
                                value: grouped.updated?.length ?? 0,
                            },
                            {
                                label: 'Removed',
                                value: grouped.removed?.length ?? 0,
                            },
                        ]}
                    />
                }
            >
                {/* Plan Header */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>{plan?.title ?? 'Strategic Plan'}</CardTitle>
                        {plan?.period && (
                            <CardDescription>
                                Period: {plan.period}
                            </CardDescription>
                        )}
                    </CardHeader>
                </Card>

                {!changes.has_snapshot ? (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3 text-muted-foreground">
                                <Info className="h-5 w-5" />
                                <span>
                                    No previous snapshot available. Changes will
                                    be tracked after the first snapshot is
                                    created.
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                ) : changes.changes.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="py-8 text-center text-muted-foreground">
                                No changes detected since last snapshot.
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {(['added', 'updated', 'removed'] as const).map(
                            (type) => {
                                const items = grouped[type];
                                if (!items?.length) return null;
                                const config = typeConfig[type];
                                const Icon = config.icon;

                                return (
                                    <Card key={type}>
                                        <CardHeader>
                                            <CardTitle
                                                className={cn(
                                                    'flex items-center gap-2',
                                                    config.color,
                                                )}
                                            >
                                                <Icon className="h-5 w-5" />
                                                {config.label} ({items.length})
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-3">
                                                {items.map((item, i) => (
                                                    <div
                                                        key={i}
                                                        className="flex items-start gap-3 rounded-lg border p-3 hover:bg-muted"
                                                    >
                                                        <Badge
                                                            className={
                                                                config.badgeClass
                                                            }
                                                        >
                                                            {config.label}
                                                        </Badge>
                                                        <div>
                                                            <p className="font-medium text-foreground">
                                                                {item.goal}
                                                            </p>
                                                            {item.detail && (
                                                                <p className="mt-1 text-sm text-muted-foreground">
                                                                    {
                                                                        item.detail
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            },
                        )}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
