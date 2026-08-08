import {
    CompensationHero,
    CompensationTabs,
    type CompensationHeroStats,
    type CompensationQuickAction,
} from '@/components/hr';
import { StatusBadge, type StatusTone } from '@/components/hr/status-badge';
import { PageLayout } from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Download, History as HistoryIcon, Plus } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type Change = {
    id: number;
    change_type: string;
    effective_date: string | null;
    previous_annual_salary: string | null;
    new_annual_salary: string | null;
    change_percentage: string | null;
    employee_profile?: { id: number; user?: { name: string } | null } | null;
    approver?: { name: string } | null;
};

type Props = {
    history: {
        data: Change[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { change_type: string | null };
    stats: CompensationHeroStats;
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'History', href: '/hr/compensation/history' },
];

const heroActions: CompensationQuickAction[] = [
    { label: 'New band', icon: Plus, href: '/hr/compensation/bands' },
    { label: 'Export', icon: Download, href: '/hr/compensation/bands/export' },
];

const CHANGE_TONE: Record<string, StatusTone> = {
    promotion: 'success',
    adjustment: 'info',
    correction: 'warning',
    initial: 'neutral',
    review: 'primary',
};

const formatDate = (value?: string | null) => {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const money = (value: string | null) => {
    if (!value) return '—';
    const n = parseFloat(value);
    if (Number.isNaN(n)) return value;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(n);
};

export default function CompensationHistoryIndex({ history, stats }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compensation & Benefits" />

            <PageLayout
                hero={
                    <CompensationHero
                        stats={stats}
                        quickActions={heroActions}
                    />
                }
            >
                <CompensationTabs active="history" />

                <Card>
                    <CardContent className="p-0">
                        {history.data.length > 0 ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Change</TableHead>
                                        <TableHead>Effective</TableHead>
                                        <TableHead>Previous</TableHead>
                                        <TableHead>New</TableHead>
                                        <TableHead>Change</TableHead>
                                        <TableHead>Approved by</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.data.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell className="font-medium">
                                                {c.employee_profile?.user
                                                    ?.name ?? 'Unknown'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={c.change_type}
                                                    tone={
                                                        CHANGE_TONE[
                                                            c.change_type
                                                        ] ?? 'neutral'
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {formatDate(c.effective_date)}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground tabular-nums">
                                                {money(
                                                    c.previous_annual_salary,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm font-medium tabular-nums">
                                                {money(c.new_annual_salary)}
                                            </TableCell>
                                            <TableCell className="text-sm tabular-nums">
                                                {c.change_percentage != null
                                                    ? `${parseFloat(c.change_percentage) >= 0 ? '+' : ''}${parseFloat(c.change_percentage)}%`
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {c.approver?.name ?? '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <EmptyState
                                icon={HistoryIcon}
                                heading="No compensation changes yet"
                                description="Pay-review applications and manual changes will appear here as a company-wide log."
                            />
                        )}
                    </CardContent>
                </Card>

                {history?.links?.length ? (
                    <LaravelPagination links={history.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
