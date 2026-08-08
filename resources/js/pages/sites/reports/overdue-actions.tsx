import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';

type OverdueAction = {
    id: number;
    reference_number?: string;
    description: string;
    severity: string;
    due_date: string;
    status: string;
    site?: { id: number; name: string; type: string; region?: string };
    assigned_to?: { id: number; name: string };
    actions: Array<{ id: number }>;
};

type Props = {
    overdueActions: OverdueAction[];
    sites: Array<{ id: number; name: string }>;
    filters: {
        site_id?: string;
        severity?: string;
        assigned_to?: string;
    };
};

const severityColors: Record<string, string> = {
    low: 'bg-muted-foreground/20 text-muted-foreground',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

export default function OverdueActions({
    overdueActions,
    sites,
    filters,
}: Props) {
    const [siteId, setSiteId] = useState(filters.site_id || '');
    const [severity, setSeverity] = useState(filters.severity || '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (siteId) params.site_id = siteId;
        if (severity) params.severity = severity;
        router.get('/sites/reports/overdue-actions', params, {
            preserveState: true,
        });
    };

    const daysOverdue = (dueDate: string) => {
        const due = new Date(dueDate);
        const now = new Date();
        return Math.floor(
            (now.getTime() - due.getTime()) / (1000 * 60 * 60 * 24),
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/sites/reports' },
                {
                    title: 'Overdue Actions',
                    href: '/sites/reports/overdue-actions',
                },
            ]}
        >
            <Head title="Overdue Corrective Actions" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Overdue Corrective Actions"
                        description="Hazards past their due date that remain open"
                        stats={[
                            {
                                label: 'Total overdue',
                                value: overdueActions.length,
                            },
                            {
                                label: 'High/Critical',
                                value: overdueActions.filter(
                                    (a) =>
                                        a.severity === 'critical' ||
                                        a.severity === 'high',
                                ).length,
                            },
                            {
                                label: 'Unassigned',
                                value: overdueActions.filter(
                                    (a) => !a.assigned_to,
                                ).length,
                            },
                        ]}
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-4">
                            <div className="w-48">
                                <Select
                                    value={siteId || undefined}
                                    onValueChange={setSiteId}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="w-40">
                                <Select
                                    value={severity || undefined}
                                    onValueChange={setSeverity}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Severity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">
                                            Medium
                                        </SelectItem>
                                        <SelectItem value="high">
                                            High
                                        </SelectItem>
                                        <SelectItem value="critical">
                                            Critical
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button variant="outline" onClick={applyFilters}>
                                Apply
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSiteId('');
                                    setSeverity('');
                                    router.get(
                                        '/sites/reports/overdue-actions',
                                        {},
                                        { preserveState: true },
                                    );
                                }}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Overdue Hazards ({overdueActions.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {overdueActions.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No overdue corrective actions found.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Assigned To</TableHead>
                                        <TableHead>Due Date</TableHead>
                                        <TableHead>Days Overdue</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {overdueActions.map((action) => (
                                        <TableRow key={action.id}>
                                            <TableCell className="font-mono text-sm">
                                                {action.reference_number ||
                                                    `#${action.id}`}
                                            </TableCell>
                                            <TableCell>
                                                {action.site?.name || 'N/A'}
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate">
                                                {action.description}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        severityColors[
                                                            action.severity
                                                        ] || ''
                                                    }
                                                >
                                                    {action.severity}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {action.assigned_to?.name || (
                                                    <span className="text-muted-foreground">
                                                        Unassigned
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {new Date(
                                                    action.due_date,
                                                ).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className="text-status-critical"
                                                >
                                                    {daysOverdue(
                                                        action.due_date,
                                                    )}{' '}
                                                    days
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={`/hazards/${action.id}`}
                                                    >
                                                        View
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
