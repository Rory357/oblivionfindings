import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRightLeft, CheckCircle2, Clock, Eye, FileText, Pencil, Plus, Search, Send } from 'lucide-react';

const ANY = '__ANY__';

const nzd = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' });

type Quote = {
    id: number;
    reference: string;
    status: string;
    total_amount: number;
    valid_until: string | null;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
    items_count: number;
};

type Props = {
    quotes: {
        data: Quote[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        total: number;
        pending: number;
        accepted: number;
        converted: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    sent: 'secondary',
    accepted: 'default',
    declined: 'destructive',
    converted: 'default',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function QuotesIndex({ quotes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/quotes', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Quotes" />
            <PageHero variant="compact"
                title="Quotes"
                description="Create and manage service quotes for clients."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Total Quotes" value={stats?.total ?? 0} icon={FileText} color="indigo" />
                    <OpsStatCard label="Pending" value={stats?.pending ?? 0} icon={Clock} color="amber" />
                    <OpsStatCard label="Accepted" value={stats?.accepted ?? 0} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Converted" value={stats?.converted ?? 0} icon={ArrowRightLeft} color="blue" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search quotes..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="sent">Sent</SelectItem>
                            <SelectItem value="accepted">Accepted</SelectItem>
                            <SelectItem value="declined">Declined</SelectItem>
                            <SelectItem value="converted">Converted</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/quotes/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Quote
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(quotes?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Quotes Found</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first quote to get started.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/quotes/create">Create Quote</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(quotes?.data ?? []).map((quote) => (
                        <Card key={quote.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/quotes/${quote.id}`} className="text-sm font-semibold hover:underline">
                                            {quote.reference}
                                        </Link>
                                        <Badge variant={STATUS_VARIANTS[quote.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                            {quote.status}
                                        </Badge>
                                        <span className="text-sm font-semibold text-status-success dark:text-status-success">
                                            {nzd.format(quote.total_amount)}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {quote.client && (
                                            <span>{quote.client.first_name} {quote.client.last_name}</span>
                                        )}
                                        <span>{quote.items_count} items</span>
                                        {quote.valid_until && (
                                            <span className={new Date(quote.valid_until) < new Date() ? 'font-medium text-status-critical' : ''}>
                                                Valid until: {formatDate(quote.valid_until)}
                                            </span>
                                        )}
                                        <span>Created: {formatDate(quote.created_at)}</span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    {quote.status === 'draft' && (
                                        <Button size="sm" variant="ghost" className="h-7 px-2 text-xs">
                                            <Send className="mr-1 h-3 w-3" /> Send
                                        </Button>
                                    )}
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/quotes/${quote.id}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/quotes/${quote.id}/edit`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(quotes?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(quotes?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
