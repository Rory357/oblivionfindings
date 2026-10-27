import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, RefreshCw, Server, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface ServerReadinessSubitem {
    key: string;
    label: string;
    queue: string;
    status: 'pass' | 'fail';
    detail: string;
}

export interface ServerReadinessCheck {
    key: string;
    title: string;
    status: 'pass' | 'fail';
    summary: string;
    detail: string;
    fix: string | null;
    subitems?: ServerReadinessSubitem[];
}

export interface ServerReadinessPayload {
    overall_status: 'pass' | 'fail';
    passed_count: number;
    failed_count: number;
    total_count: number;
    checked_at: string;
    checks: ServerReadinessCheck[];
}

interface Props {
    readiness: ServerReadinessPayload;
}

type FilterKey = 'all' | 'fail' | 'pass';

export default function ServerReadinessPage({ readiness }: Props) {
    const [filter, setFilter] = useState<FilterKey>('all');
    const [refreshing, setRefreshing] = useState(false);

    const visibleChecks = useMemo(() => {
        if (filter === 'all') {
            return readiness.checks;
        }
        return readiness.checks.filter((check) => check.status === filter);
    }, [filter, readiness.checks]);

    function handleRefresh() {
        if (refreshing) return;
        setRefreshing(true);
        router.reload({
            only: ['readiness'],
            onFinish: () => setRefreshing(false),
        });
    }

    const overallChipLabel =
        readiness.overall_status === 'pass'
            ? 'Ready'
            : `${readiness.failed_count} ${readiness.failed_count === 1 ? 'item needs' : 'items need'} attention`;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Settings', href: '/settings/profile' },
                {
                    title: 'Server readiness',
                    href: '/settings/server-readiness',
                },
            ]}
        >
            <Head title="Server readiness" />
            <SettingsLayout>
                <div className="flex flex-col gap-5">
                    <PageHeader
                        icon={Server}
                        title="Server readiness"
                        subline={`Live checks for queues, background workers, network ping and security keys · checked ${formatDateTime(readiness.checked_at)}`}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    readiness.overall_status === 'pass'
                                        ? 'success'
                                        : 'critical'
                                }
                            >
                                {overallChipLabel}
                            </PageHeaderStatusChip>
                        }
                        actions={
                            <PageHeaderGlassButton
                                onClick={handleRefresh}
                                disabled={refreshing}
                            >
                                <RefreshCw className="mr-1.5 h-4 w-4" />
                                {refreshing ? 'Checking…' : 'Check again'}
                            </PageHeaderGlassButton>
                        }
                        rail={
                            <PageHeaderRail
                                value={filter}
                                onSelect={setFilter}
                                ariaLabel="Readiness checks"
                                items={[
                                    {
                                        key: 'all',
                                        label: 'All checks',
                                        count: readiness.total_count,
                                    },
                                    {
                                        key: 'fail',
                                        label: 'Needs fix',
                                        count: readiness.failed_count,
                                        alert: readiness.failed_count > 0,
                                    },
                                    {
                                        key: 'pass',
                                        label: 'Ready',
                                        count: readiness.passed_count,
                                    },
                                ]}
                            />
                        }
                    />

                    {visibleChecks.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center">
                                <p className="text-sm font-medium text-foreground">
                                    {filter === 'fail'
                                        ? 'Every server readiness check is currently passing'
                                        : 'No checks match this filter'}
                                </p>
                                <p className="text-caption mt-1">
                                    Switch back to All checks to review the full
                                    list.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-5">
                            {visibleChecks.map((item) => {
                                const passed = item.status === 'pass';
                                return (
                                    <Card
                                        key={item.key}
                                        data-testid={`readiness-check-${item.key}`}
                                    >
                                        <CardHeader className="pb-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="flex items-start gap-2.5">
                                                    {passed ? (
                                                        <CheckCircle2
                                                            aria-hidden="true"
                                                            className="mt-0.5 h-5 w-5 shrink-0 text-status-success"
                                                        />
                                                    ) : (
                                                        <XCircle
                                                            aria-hidden="true"
                                                            className="mt-0.5 h-5 w-5 shrink-0 text-status-critical"
                                                        />
                                                    )}
                                                    <div>
                                                        <CardTitle className="text-base">
                                                            {item.title}
                                                        </CardTitle>
                                                        <p className="text-caption mt-0.5">
                                                            {item.summary}
                                                        </p>
                                                    </div>
                                                </div>
                                                <StatusBadge
                                                    variant={
                                                        passed
                                                            ? 'success'
                                                            : 'critical'
                                                    }
                                                >
                                                    {passed
                                                        ? 'Ready'
                                                        : 'Needs fix'}
                                                </StatusBadge>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <p className="text-sm text-foreground">
                                                {item.detail}
                                            </p>

                                            {item.fix ? (
                                                <div className="rounded-md border border-status-critical/30 bg-status-critical-bg px-3.5 py-2.5 text-status-critical-foreground">
                                                    <p className="text-xs font-semibold">
                                                        How to fix
                                                    </p>
                                                    <p className="mt-1 text-sm">
                                                        {item.fix}
                                                    </p>
                                                </div>
                                            ) : null}

                                            {item.subitems &&
                                            item.subitems.length > 0 ? (
                                                <div className="grid gap-2.5 pt-1 sm:grid-cols-2">
                                                    {item.subitems.map(
                                                        (subitem) => {
                                                            const subPassed =
                                                                subitem.status ===
                                                                'pass';
                                                            return (
                                                                <div
                                                                    key={
                                                                        subitem.key
                                                                    }
                                                                    data-testid={`worker-pool-${subitem.key}`}
                                                                    className="flex items-start justify-between gap-3 rounded-md border border-border bg-muted px-3 py-2.5"
                                                                >
                                                                    <div className="min-w-0">
                                                                        <div className="flex items-center gap-1.5">
                                                                            {subPassed ? (
                                                                                <CheckCircle2
                                                                                    aria-hidden="true"
                                                                                    className="h-4 w-4 shrink-0 text-status-success"
                                                                                />
                                                                            ) : (
                                                                                <XCircle
                                                                                    aria-hidden="true"
                                                                                    className="h-4 w-4 shrink-0 text-status-critical"
                                                                                />
                                                                            )}
                                                                            <span className="truncate text-sm font-medium text-foreground">
                                                                                {
                                                                                    subitem.label
                                                                                }
                                                                            </span>
                                                                        </div>
                                                                        <p className="text-caption mt-0.5 pl-5.5">
                                                                            {
                                                                                subitem.detail
                                                                            }{' '}
                                                                            ·
                                                                            queue{' '}
                                                                            {
                                                                                subitem.queue
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                    <StatusBadge
                                                                        size="sm"
                                                                        variant={
                                                                            subPassed
                                                                                ? 'success'
                                                                                : 'critical'
                                                                        }
                                                                    >
                                                                        {subPassed
                                                                            ? 'Running'
                                                                            : 'Stopped'}
                                                                    </StatusBadge>
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            ) : null}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
