import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { TrendingUp } from 'lucide-react';

type FailedItem = {
    template_id: number;
    template_name: string;
    item_text: string;
    failure_count: number;
    first_failure: string;
    last_failure: string;
};

type Props = {
    failedItems: FailedItem[];
    dateRange: { from: string; to: string };
};

export default function ChecklistTrends({ failedItems, dateRange }: Props) {
    const maxFailures =
        failedItems.length > 0
            ? Math.max(...failedItems.map((i) => i.failure_count))
            : 1;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/sites/reports' },
                {
                    title: 'Checklist Trends',
                    href: '/sites/reports/checklist-trends',
                },
            ]}
        >
            <Head title="Checklist Failure Trends" />

            <PageLayout
                hero={
                    <PageHero
                        icon={TrendingUp}
                        title="Checklist Failure Trends"
                        description={`Most frequently failed checklist items over the last 3 months (${dateRange.from} to ${dateRange.to})`}
                        stats={[
                            {
                                label: 'Unique items',
                                value: failedItems.length,
                            },
                            {
                                label: 'Total failures',
                                value: failedItems.reduce(
                                    (sum, i) => sum + i.failure_count,
                                    0,
                                ),
                            },
                            {
                                label: 'Affected templates',
                                value: new Set(
                                    failedItems.map((i) => i.template_id),
                                ).size,
                            },
                        ]}
                    />
                }
            >
                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Most Failed Items
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {failedItems.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No failed checklist items found in the last 3
                                months.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-8">#</TableHead>
                                        <TableHead>Template</TableHead>
                                        <TableHead>Question / Item</TableHead>
                                        <TableHead>Failures</TableHead>
                                        <TableHead>Frequency</TableHead>
                                        <TableHead>First Failure</TableHead>
                                        <TableHead>Last Failure</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {failedItems.map((item, index) => {
                                        const barWidth = Math.round(
                                            (item.failure_count / maxFailures) *
                                                100,
                                        );
                                        return (
                                            <TableRow
                                                key={`${item.template_id}-${index}`}
                                            >
                                                <TableCell className="text-muted-foreground">
                                                    {index + 1}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {item.template_name}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="max-w-sm">
                                                    <span className="text-sm">
                                                        {item.item_text}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        className={
                                                            item.failure_count >=
                                                            10
                                                                ? 'bg-status-critical-bg text-status-critical'
                                                                : item.failure_count >=
                                                                    5
                                                                  ? 'bg-status-warning-bg text-status-warning'
                                                                  : 'bg-status-warning-bg text-status-warning'
                                                        }
                                                    >
                                                        {item.failure_count}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="h-2 w-24 rounded-full bg-muted">
                                                        <div
                                                            className="h-2 rounded-full bg-status-warning"
                                                            style={{
                                                                width: `${barWidth}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {new Date(
                                                        item.first_failure,
                                                    ).toLocaleDateString()}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {new Date(
                                                        item.last_failure,
                                                    ).toLocaleDateString()}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
