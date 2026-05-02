import PageHeader from '@/components/page-header';
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
import { useState } from 'react';
import {
    formatDate,
    type PaginationPayload,
    type RoadmapCan,
    statusLabel,
} from '../shared';

type InitiativeRow = {
    id: number;
    code?: string | null;
    title: string;
    stream?: string | null;
    status: string;
    priority_score?: number | string | null;
    owner?: { name?: string | null } | null;
    sponsor?: { name?: string | null } | null;
    next_decision?: string | null;
    decision_due_at?: string | null;
};

type Props = {
    items: PaginationPayload<InitiativeRow>;
    filters: {
        status?: string | null;
        stream?: string | null;
        fiscal_year?: string | null;
        quarter?: string | null;
    };
    can: RoadmapCan;
};

const statusOptions = [
    '',
    'draft',
    'proposed',
    'approved',
    'in_progress',
    'blocked',
    'on_hold',
    'deferred',
    'completed',
    'cancelled',
];

const streamOptions = [
    '',
    'it',
    'maintenance',
    'facilities',
    'operations',
    'overheads',
    'continuous_improvement',
];

export default function InitiativeIndex({ items, filters }: Props) {
    const [status, setStatus] = useState(filters.status ?? '');
    const [stream, setStream] = useState(filters.stream ?? '');
    const [fiscalYear, setFiscalYear] = useState(filters.fiscal_year ?? '');
    const [quarter, setQuarter] = useState(filters.quarter ?? '');

    const applyFilters = () => {
        router.get(
            '/roadmap/initiatives',
            {
                status: status || undefined,
                stream: stream || undefined,
                fiscal_year: fiscalYear || undefined,
                quarter: quarter || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Roadmap Initiatives" />
            <PageHeader
                title="Roadmap Initiatives"
                description="Prioritised initiative register for roadmap planning and governance review."
                backHref="/roadmap/dashboard"
            />
            <PageShell>
                <Card className="mb-4">
                    <CardContent className="grid gap-3 p-4 md:grid-cols-5">
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            aria-label="Initiative status"
                        >
                            {statusOptions.map((option) => (
                                <option key={option || 'all'} value={option}>
                                    {option ? statusLabel(option) : 'All statuses'}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={stream}
                            onChange={(event) => setStream(event.target.value)}
                            aria-label="Initiative stream"
                        >
                            {streamOptions.map((option) => (
                                <option key={option || 'all'} value={option}>
                                    {option ? statusLabel(option) : 'All streams'}
                                </option>
                            ))}
                        </select>
                        <Input
                            inputMode="numeric"
                            placeholder="Fiscal year"
                            value={fiscalYear}
                            onChange={(event) => setFiscalYear(event.target.value)}
                        />
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={quarter}
                            onChange={(event) => setQuarter(event.target.value)}
                            aria-label="Initiative quarter"
                        >
                            <option value="">All quarters</option>
                            <option value="1">Q1</option>
                            <option value="2">Q2</option>
                            <option value="3">Q3</option>
                            <option value="4">Q4</option>
                        </select>
                        <Button onClick={applyFilters}>Apply Filters</Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table data-testid="initiative-register-table">
                                <caption className="sr-only">
                                    Roadmap initiative register
                                </caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Initiative</TableHead>
                                        <TableHead>Stream</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Score</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Next Decision</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-muted-foreground">
                                                No initiatives match these filters.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {items.data.map((initiative) => (
                                        <TableRow key={initiative.id}>
                                            <TableCell className="max-w-[360px]">
                                                <div className="font-medium">{initiative.title}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {initiative.code ?? `INIT-${initiative.id}`}
                                                </div>
                                            </TableCell>
                                            <TableCell>{statusLabel(initiative.stream)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{statusLabel(initiative.status)}</Badge>
                                            </TableCell>
                                            <TableCell>{initiative.priority_score ?? '-'}</TableCell>
                                            <TableCell>{initiative.owner?.name ?? '-'}</TableCell>
                                            <TableCell className="max-w-[320px]">
                                                <div>{initiative.next_decision ?? '-'}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {formatDate(initiative.decision_due_at)}
                                                </div>
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
                        Showing {items.data.length} of {items.total} initiatives.
                    </div>
                    <LaravelPagination links={items.links} lastPage={items.last_page} />
                </div>

                <div className="mt-4">
                    <Link href="/roadmap/dashboard">
                        <Button variant="outline">Return to Dashboard</Button>
                    </Link>
                </div>
            </PageShell>
        </AppLayout>
    );
}
