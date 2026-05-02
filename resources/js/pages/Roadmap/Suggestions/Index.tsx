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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    extractErrorMessage,
    formatDate,
    type ManagerOption,
    type PaginationPayload,
    type RoadmapCan,
    statusLabel,
} from '../shared';

type SuggestionRow = {
    id: number;
    title: string;
    source: string;
    status: string;
    hit_count?: number | null;
    score_hint?: number | string | null;
    last_seen_at?: string | null;
    summary?: string | null;
    triage_owner_id?: number | null;
    triage_owner?: { name?: string | null; email?: string | null } | null;
    triage_notes?: string | null;
};

type Props = {
    items: PaginationPayload<SuggestionRow>;
    filters: {
        status?: string | null;
        source?: string | null;
    };
    managers: ManagerOption[];
    can: RoadmapCan;
};

const statusOptions = ['', 'triage_pending', 'accepted', 'rejected', 'snoozed', 'converted'];

export default function SuggestionIndex({ items, filters, managers, can }: Props) {
    const [status, setStatus] = useState(filters.status ?? 'triage_pending');
    const [source, setSource] = useState(filters.source ?? '');
    const [notes, setNotes] = useState<Record<number, string>>(() =>
        Object.fromEntries(items.data.map((item) => [item.id, item.triage_notes ?? ''])),
    );
    const [loadingKey, setLoadingKey] = useState<string | null>(null);
    const currentYear = new Date().getFullYear();
    const currentQuarter = Math.floor(new Date().getMonth() / 3) + 1;
    const defaultOwnerId = managers[0]?.id ?? null;

    const sources = useMemo(
        () => Array.from(new Set(items.data.map((item) => item.source))).sort(),
        [items.data],
    );

    const applyFilters = () => {
        router.get(
            '/roadmap/suggestions',
            {
                status: status || undefined,
                source: source || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const triage = async (
        suggestion: SuggestionRow,
        nextStatus: 'triage_pending' | 'accepted' | 'rejected' | 'snoozed',
        triageOwnerId?: number | null,
    ) => {
        if (!can.manageRoadmap) return;

        const key = `${suggestion.id}:${nextStatus}`;
        setLoadingKey(key);
        try {
            await axios.post(
                `/roadmap/suggestions/${suggestion.id}/triage`,
                {
                    status: nextStatus,
                    triage_owner_id: triageOwnerId ?? suggestion.triage_owner_id ?? undefined,
                    triage_notes: notes[suggestion.id]?.trim() || null,
                    snoozed_until:
                        nextStatus === 'snoozed'
                            ? new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)
                                  .toISOString()
                                  .slice(0, 10)
                            : undefined,
                },
                { headers: { Accept: 'application/json' } },
            );
            toast.success('Suggestion updated.');
            router.reload({ preserveScroll: true });
        } catch (error) {
            toast.error(extractErrorMessage(error, 'Failed to update suggestion.'));
        } finally {
            setLoadingKey(null);
        }
    };

    const convert = async (suggestion: SuggestionRow) => {
        if (!can.manageRoadmap) return;

        setLoadingKey(`${suggestion.id}:convert`);
        try {
            await axios.post(
                `/roadmap/suggestions/${suggestion.id}/convert`,
                {
                    owner_user_id: suggestion.triage_owner_id ?? defaultOwnerId ?? undefined,
                    target_fiscal_year: currentYear,
                    target_quarter: currentQuarter,
                    triage_notes: notes[suggestion.id]?.trim() || null,
                },
                { headers: { Accept: 'application/json' } },
            );
            toast.success('Suggestion converted to initiative.');
            router.reload({ preserveScroll: true });
        } catch (error) {
            toast.error(extractErrorMessage(error, 'Failed to convert suggestion.'));
        } finally {
            setLoadingKey(null);
        }
    };

    return (
        <AppLayout>
            <Head title="Roadmap Suggestions" />
            <PageHeader
                title="Roadmap Suggestions"
                description="Triage backlog for operational signals that should become roadmap work."
                backHref="/roadmap/dashboard"
            />
            <PageShell>
                <Card className="mb-4">
                    <CardContent className="grid gap-3 p-4 md:grid-cols-4">
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                            aria-label="Suggestion status"
                        >
                            {statusOptions.map((option) => (
                                <option key={option || 'all'} value={option}>
                                    {option ? statusLabel(option) : 'All statuses'}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={source}
                            onChange={(event) => setSource(event.target.value)}
                            aria-label="Suggestion source"
                        >
                            <option value="">All sources</option>
                            {sources.map((option) => (
                                <option key={option} value={option}>
                                    {statusLabel(option)}
                                </option>
                            ))}
                        </select>
                        <Button onClick={applyFilters}>Apply Filters</Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table data-testid="suggestion-backlog-table">
                                <caption className="sr-only">Roadmap suggestion backlog</caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Suggestion</TableHead>
                                        <TableHead>Source</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Notes</TableHead>
                                        {can.manageRoadmap && <TableHead>Actions</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={can.manageRoadmap ? 6 : 5}
                                                className="text-muted-foreground"
                                            >
                                                No suggestions match these filters.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {items.data.map((suggestion) => (
                                        <TableRow key={suggestion.id}>
                                            <TableCell className="min-w-[300px]">
                                                <div className="font-medium">{suggestion.title}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {suggestion.summary ?? 'No summary'} · {suggestion.hit_count ?? 0} hits · last seen {formatDate(suggestion.last_seen_at)}
                                                </div>
                                            </TableCell>
                                            <TableCell>{statusLabel(suggestion.source)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{statusLabel(suggestion.status)}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                {can.manageRoadmap ? (
                                                    <select
                                                        className="h-9 min-w-[180px] rounded-md border bg-background px-2 text-sm"
                                                        defaultValue={suggestion.triage_owner_id ?? ''}
                                                        onChange={(event) =>
                                                            void triage(
                                                                suggestion,
                                                                'triage_pending',
                                                                event.target.value ? Number(event.target.value) : null,
                                                            )
                                                        }
                                                        aria-label={`Assign ${suggestion.title}`}
                                                    >
                                                        <option value="">Unassigned</option>
                                                        {managers.map((manager) => (
                                                            <option key={manager.id} value={manager.id}>
                                                                {manager.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    suggestion.triage_owner?.name ?? 'Unassigned'
                                                )}
                                            </TableCell>
                                            <TableCell className="min-w-[260px]">
                                                {can.manageRoadmap ? (
                                                    <Textarea
                                                        value={notes[suggestion.id] ?? ''}
                                                        onChange={(event) =>
                                                            setNotes((current) => ({
                                                                ...current,
                                                                [suggestion.id]: event.target.value,
                                                            }))
                                                        }
                                                        rows={2}
                                                        aria-label={`Notes for ${suggestion.title}`}
                                                    />
                                                ) : (
                                                    suggestion.triage_notes ?? '-'
                                                )}
                                            </TableCell>
                                            {can.manageRoadmap && (
                                                <TableCell>
                                                    <div className="flex min-w-[260px] flex-wrap gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={loadingKey === `${suggestion.id}:triage_pending`}
                                                            onClick={() => void triage(suggestion, 'triage_pending')}
                                                        >
                                                            Save
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={loadingKey === `${suggestion.id}:accepted`}
                                                            onClick={() => void triage(suggestion, 'accepted')}
                                                        >
                                                            Accept
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={loadingKey === `${suggestion.id}:rejected`}
                                                            onClick={() => void triage(suggestion, 'rejected')}
                                                        >
                                                            Reject
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            disabled={loadingKey === `${suggestion.id}:convert`}
                                                            onClick={() => void convert(suggestion)}
                                                        >
                                                            Convert
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
                        Showing {items.data.length} of {items.total} suggestions.
                    </div>
                    <LaravelPagination links={items.links} lastPage={items.last_page} />
                </div>
            </PageShell>
        </AppLayout>
    );
}
