import {
    LedgerTabsFooter,
    NewJournalDialog,
    formatMoney,
    useRowContextMenu,
    type RowCtxItem,
} from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
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
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { BookOpen, Download, Eye, Plus, Search } from 'lucide-react';
import { useState } from 'react';

interface JournalLine {
    id: number;
}

interface Journal {
    id: number;
    journal_number: string;
    journal_date: string;
    type: string;
    description: string | null;
    total_amount: string;
    status: string;
    lines_count: number;
}

interface PaginatedJournals {
    data: Journal[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Filters {
    status?: string;
    type?: string;
    date_from?: string;
    date_to?: string;
    search?: string;
}

interface RefItem {
    id: number;
    code: string;
    name: string;
}

interface Props extends PageProps {
    journals: PaginatedJournals;
    filters: Filters;
    canManage?: boolean;
    accounts?: RefItem[];
    costCentres?: RefItem[];
    fundingStreams?: RefItem[];
}

const statusBadge = (status: string) => {
    const map: Record<string, string> = {
        draft: 'bg-muted text-foreground',
        posted: 'bg-status-success-bg text-status-success',
        reversed: 'bg-status-critical-bg text-status-critical',
    };
    return map[status] ?? 'bg-muted text-foreground';
};

const typeBadge = (type: string) => {
    const map: Record<string, string> = {
        standard: 'bg-status-info-bg text-status-info',
        adjustment: 'bg-status-warning-bg text-status-warning',
        opening: 'bg-primary/10 text-primary',
    };
    return map[type] ?? 'bg-muted text-foreground';
};

export default function JournalsIndex({
    auth,
    journals,
    filters,
    canManage = false,
    accounts = [],
    costCentres = [],
    fundingStreams = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (status) params.status = status;
        if (type) params.type = type;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;
        router.get('/finance/journals', params, { preserveState: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setType('');
        setDateFrom('');
        setDateTo('');
        router.get('/finance/journals', {}, { preserveState: true });
    };

    const hasFilters = Boolean(search || status || type || dateFrom || dateTo);

    const postedCount = journals.data.filter(
        (j) => j.status === 'posted',
    ).length;
    const draftCount = journals.data.filter((j) => j.status === 'draft').length;

    // Right-click row menu — mirrors the row's existing navigation (Open).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (journal: Journal): RowCtxItem[] => [
        {
            kind: 'item',
            label: 'Open',
            icon: Eye,
            onSelect: () => router.visit(`/finance/journals/${journal.id}`),
        },
    ];

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Journals', href: '/finance/journals' },
            ]}
        >
            <Head title="Journals" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        footer={<LedgerTabsFooter active="journals" />}
                        icon={BookOpen}
                        title="Journals"
                        description="General ledger journal entries"
                        stats={[
                            { label: 'Total', value: journals.total },
                            { label: 'Posted (this page)', value: postedCount },
                            { label: 'Drafts (this page)', value: draftCount },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={`/finance/journals/export?${new URLSearchParams(Object.entries({ status, type, date_from: dateFrom, date_to: dateTo, search }).filter(([, v]) => v)).toString()}`}
                                    >
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Journal
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search number or description..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' && applyFilters()
                                    }
                                    className="pl-9"
                                />
                            </div>

                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger aria-label="Filter by status">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="posted">
                                        Posted
                                    </SelectItem>
                                    <SelectItem value="reversed">
                                        Reversed
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger aria-label="Filter by type">
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="standard">
                                        Standard
                                    </SelectItem>
                                    <SelectItem value="adjustment">
                                        Adjustment
                                    </SelectItem>
                                    <SelectItem value="opening">
                                        Opening
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                placeholder="From date"
                            />

                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                placeholder="To date"
                            />
                        </div>

                        <div className="mt-4 flex gap-2">
                            <Button size="sm" onClick={applyFilters}>
                                Apply Filters
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={clearFilters}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Journal Number</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Total Amount
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journals.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            {hasFilters ? (
                                                <EmptySearch
                                                    onClear={clearFilters}
                                                    title="No journals match your search"
                                                    className="border-0"
                                                />
                                            ) : (
                                                <EmptyList
                                                    icon={BookOpen}
                                                    itemName="journal"
                                                    title="No journals yet"
                                                    description="Create your first general ledger journal entry to get started."
                                                    className="border-0"
                                                    action={
                                                        canManage ? (
                                                            <Button
                                                                size="sm"
                                                                onClick={() =>
                                                                    setCreateOpen(
                                                                        true,
                                                                    )
                                                                }
                                                            >
                                                                New journal
                                                            </Button>
                                                        ) : undefined
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {journals.data.map((journal) => (
                                    <TableRow
                                        key={journal.id}
                                        className="cursor-pointer hover:bg-muted"
                                        onClick={() =>
                                            router.visit(
                                                `/finance/journals/${journal.id}`,
                                            )
                                        }
                                        onContextMenu={rowMenu.open(
                                            rowMenuItems(journal),
                                        )}
                                    >
                                        <TableCell className="font-medium">
                                            {journal.journal_number}
                                        </TableCell>
                                        <TableCell>
                                            {new Date(
                                                journal.journal_date,
                                            ).toLocaleDateString('en-NZ')}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={typeBadge(
                                                    journal.type,
                                                )}
                                            >
                                                {journal.type
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                    journal.type.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate">
                                            {journal.description ?? '-'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {formatMoney(journal.total_amount)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={statusBadge(
                                                    journal.status,
                                                )}
                                            >
                                                {journal.status
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                    journal.status.slice(1)}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {journals.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(journals.current_page - 1) * journals.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                journals.current_page * journals.per_page,
                                journals.total,
                            )}{' '}
                            of {journals.total} journals
                        </p>
                        <div className="flex gap-1">
                            {journals.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url && router.visit(link.url)
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}

                {canManage && (
                    <NewJournalDialog
                        open={createOpen}
                        onClose={() => setCreateOpen(false)}
                        accounts={accounts}
                        costCentres={costCentres}
                        fundingStreams={fundingStreams}
                    />
                )}

                {rowMenu.element}
            </PageLayout>
        </AppLayout>
    );
}
