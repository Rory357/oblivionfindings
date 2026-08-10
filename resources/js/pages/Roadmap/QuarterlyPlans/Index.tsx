import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { Head, Link, router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useState } from 'react';
import {
    type PaginationPayload,
    type RoadmapCan,
    statusLabel,
} from '../shared';

type PlanRow = {
    id: number;
    fiscal_year: number;
    quarter: number;
    status: string;
    revision_no: number;
    preset_profile?: string | null;
    items_count?: number | null;
};

type Props = {
    items: PaginationPayload<PlanRow>;
    filters: {
        fiscal_year?: string | null;
        quarter?: string | null;
        status?: string | null;
    };
    can: RoadmapCan;
};

const statusOptions = [
    '',
    'draft',
    'manager_review',
    'exec_review',
    'approved',
    'published',
    'closed',
];

export default function QuarterlyPlanIndex({ items, filters }: Props) {
    const [fiscalYear, setFiscalYear] = useState(filters.fiscal_year ?? '');
    const [quarter, setQuarter] = useState(filters.quarter ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    const applyFilters = () => {
        router.get(
            '/roadmap/quarterly-plans',
            {
                fiscal_year: fiscalYear || undefined,
                quarter: quarter || undefined,
                status: status || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Roadmap Quarterly Plans" />
            <PageHero
                icon={CalendarRange}
                title="Roadmap Quarterly Plans"
                description="Plan history by fiscal quarter, revision, status, and approval state."
                stats={[
                    { label: 'Total', value: items.data?.length ?? 0 },
                    {
                        label: 'Approved',
                        value:
                            items.data?.filter((p) => p.status === 'approved')
                                .length ?? 0,
                    },
                    {
                        label: 'Draft',
                        value:
                            items.data?.filter((p) => p.status === 'draft')
                                .length ?? 0,
                    },
                ]}
            />
            <PageShell>
                <Card className="mb-4">
                    <CardContent className="grid gap-3 p-4 md:grid-cols-4">
                        <Input
                            inputMode="numeric"
                            placeholder="Fiscal year"
                            value={fiscalYear}
                            onChange={(event) =>
                                setFiscalYear(event.target.value)
                            }
                        />
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={quarter}
                            onChange={(event) => setQuarter(event.target.value)}
                            aria-label="Plan quarter"
                        >
                            <option value="">All quarters</option>
                            <option value="1">Q1</option>
                            <option value="2">Q2</option>
                            <option value="3">Q3</option>
                            <option value="4">Q4</option>
                        </select>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            aria-label="Plan status"
                        >
                            {statusOptions.map((option) => (
                                <option key={option || 'all'} value={option}>
                                    {option
                                        ? statusLabel(option)
                                        : 'All statuses'}
                                </option>
                            ))}
                        </select>
                        <Button onClick={applyFilters}>Apply Filters</Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table data-testid="quarterly-plan-history-table">
                                <caption className="sr-only">
                                    Roadmap quarterly plan history
                                </caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Quarter</TableHead>
                                        <TableHead>Revision</TableHead>
                                        <TableHead>Preset</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Items</TableHead>
                                        <TableHead>Detail</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="text-muted-foreground"
                                            >
                                                No quarterly plans match these
                                                filters.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {items.data.map((plan) => (
                                        <TableRow key={plan.id}>
                                            <TableCell>{`FY${plan.fiscal_year} Q${plan.quarter}`}</TableCell>
                                            <TableCell>
                                                {plan.revision_no}
                                            </TableCell>
                                            <TableCell>
                                                {statusLabel(
                                                    plan.preset_profile,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {statusLabel(plan.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {plan.items_count ?? 0}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={`/roadmap/quarterly-plans/${plan.id}`}
                                                >
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        Open
                                                    </Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="text-sm text-muted-foreground">
                        Showing {items.data.length} of {items.total} plans.
                    </div>
                    <LaravelPagination
                        links={items.links}
                        lastPage={items.last_page}
                    />
                </div>
            </PageShell>
        </AppLayout>
    );
}
