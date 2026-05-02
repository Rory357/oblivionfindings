import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    extractErrorMessage,
    formatCurrency,
    formatDate,
    type PaginationPayload,
    type RoadmapCan,
    statusLabel,
} from '../shared';

type DecisionRow = {
    id: number;
    request_type: string;
    required_role?: string | null;
    amount?: number | string | null;
    due_date?: string | null;
    status: string;
    rationale?: string | null;
    recommendation?: string | null;
    requester?: { name?: string | null } | null;
};

type Props = {
    items: PaginationPayload<DecisionRow>;
    filters: { status?: string | null };
    can: RoadmapCan;
};

const statusOptions = ['', 'pending', 'approved', 'rejected'];

export default function DecisionIndex({ items, filters, can }: Props) {
    const [status, setStatus] = useState(filters.status ?? 'pending');
    const [loadingKey, setLoadingKey] = useState<string | null>(null);

    const applyFilters = () => {
        router.get(
            '/roadmap/decisions',
            { status: status || undefined },
            { preserveState: true, replace: true },
        );
    };

    const resolveDecision = async (decisionId: number, nextStatus: 'approved' | 'rejected') => {
        if (!can.manageDecisions) return;

        const key = `${decisionId}:${nextStatus}`;
        setLoadingKey(key);
        try {
            await axios.post(
                `/roadmap/decisions/${decisionId}/resolve`,
                {
                    status: nextStatus,
                    notes:
                        nextStatus === 'approved'
                            ? 'Approved from roadmap decisions queue.'
                            : 'Rejected from roadmap decisions queue.',
                },
                { headers: { Accept: 'application/json' } },
            );
            toast.success(`Decision request ${nextStatus}.`);
            router.reload({ preserveScroll: true });
        } catch (error) {
            toast.error(extractErrorMessage(error, 'Failed to resolve decision request.'));
        } finally {
            setLoadingKey(null);
        }
    };

    return (
        <AppLayout>
            <Head title="Roadmap Decisions" />
            <PageHeader
                title="Roadmap Decisions"
                description="Pending governance and roadmap decision requests."
                backHref="/roadmap/dashboard"
            />
            <PageShell>
                <Card className="mb-4">
                    <CardContent className="flex flex-wrap items-center gap-3 p-4">
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            aria-label="Decision status"
                        >
                            {statusOptions.map((option) => (
                                <option key={option || 'all'} value={option}>
                                    {option ? statusLabel(option) : 'All statuses'}
                                </option>
                            ))}
                        </select>
                        <Button onClick={applyFilters}>Apply Filters</Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table data-testid="roadmap-decisions-table">
                                <caption className="sr-only">Roadmap decision requests</caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Due</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Rationale</TableHead>
                                        {can.manageDecisions && <TableHead>Actions</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={can.manageDecisions ? 7 : 6}
                                                className="text-muted-foreground"
                                            >
                                                No decision requests match these filters.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {items.data.map((decision) => (
                                        <TableRow key={decision.id}>
                                            <TableCell>{statusLabel(decision.request_type)}</TableCell>
                                            <TableCell>{statusLabel(decision.required_role)}</TableCell>
                                            <TableCell>{formatCurrency(decision.amount)}</TableCell>
                                            <TableCell>{formatDate(decision.due_date)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{statusLabel(decision.status)}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-[360px]">
                                                <div>{decision.rationale ?? '-'}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {decision.recommendation ?? ''}
                                                </div>
                                            </TableCell>
                                            {can.manageDecisions && (
                                                <TableCell>
                                                    <div className="flex min-w-[160px] flex-wrap gap-2">
                                                        <Button
                                                            size="sm"
                                                            disabled={loadingKey === `${decision.id}:approved`}
                                                            onClick={() => void resolveDecision(decision.id, 'approved')}
                                                        >
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={loadingKey === `${decision.id}:rejected`}
                                                            onClick={() => void resolveDecision(decision.id, 'rejected')}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="text-sm text-muted-foreground">
                        Showing {items.data.length} of {items.total} decisions.
                    </div>
                    <LaravelPagination links={items.links} lastPage={items.last_page} />
                </div>
            </PageShell>
        </AppLayout>
    );
}
