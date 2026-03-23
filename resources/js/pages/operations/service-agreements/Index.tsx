import { OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, DollarSign, Eye, FileText, Pencil, Plus, Search } from 'lucide-react';

const ANY = '__ANY__';

type Agreement = {
    id: number;
    title: string;
    reference_number: string | null;
    status: string;
    agreement_type: string;
    funding_body: string | null;
    starts_at: string | null;
    ends_at: string | null;
    total_budget: number;
    budget_used: number;
    budget_remaining: number;
    budget_utilisation_percent: number;
    client: { id: number; first_name: string; last_name: string } | null;
    line_items_count: number;
};

type Props = {
    agreements: {
        data: Agreement[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { q?: string; status?: string; agreement_type?: string };
    stats: { total: number; active: number; expiring_soon: number; total_budget: number; total_used: number };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    draft: 'outline',
    expired: 'secondary',
    terminated: 'destructive',
};

const TYPE_LABELS: Record<string, string> = {
    ndis: 'NDIS',
    private: 'Private',
    block: 'Block',
    spot: 'Spot',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(n);
}

export default function ServiceAgreementsIndex({ agreements = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/service-agreements', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Service Agreements" />
            <PageHeader title="Service Agreements" description="Manage funding agreements, budgets, and service contracts." backHref="/operations" />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Active Agreements" value={stats?.active ?? 0} icon={FileText} color="indigo" />
                    <OpsStatCard label="Total Budget" value={formatCurrency(stats?.total_budget ?? 0)} icon={DollarSign} color="emerald" />
                    <OpsStatCard label="Budget Used" value={formatCurrency(stats?.total_used ?? 0)} icon={DollarSign} color="blue" />
                    <OpsStatCard label="Expiring Soon" value={stats?.expiring_soon ?? 0} icon={AlertTriangle} color={stats?.expiring_soon > 0 ? 'amber' : 'slate'} />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search agreements..." className="h-9 pl-8 text-sm" defaultValue={filters?.q ?? ''} onChange={(e) => updateFilters('q', e.target.value || null)} />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="terminated">Terminated</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters?.agreement_type ?? ANY} onValueChange={(v) => updateFilters('agreement_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[120px] text-xs"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(TYPE_LABELS).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/service-agreements/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> New Agreement
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(agreements?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Service Agreements</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create service agreements to track funding and budgets.</p>
                            </CardContent>
                        </Card>
                    )}
                    {(agreements?.data ?? []).map((ag) => (
                        <Card key={ag.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/service-agreements/${ag.id}`} className="text-sm font-semibold hover:underline">
                                            {ag.title}
                                        </Link>
                                        <Badge variant={STATUS_VARIANTS[ag.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">{ag.status}</Badge>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px]">{TYPE_LABELS[ag.agreement_type] ?? ag.agreement_type}</Badge>
                                        {ag.reference_number && <span className="text-[10px] text-muted-foreground">#{ag.reference_number}</span>}
                                    </div>
                                    <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                        {ag.client && <span>{ag.client.first_name} {ag.client.last_name}</span>}
                                        {ag.funding_body && <span>{ag.funding_body}</span>}
                                        {ag.starts_at && <span className="flex items-center gap-1"><CalendarDays className="h-3 w-3" />{formatDate(ag.starts_at)} - {formatDate(ag.ends_at)}</span>}
                                        <span>{ag.line_items_count} line items</span>
                                    </div>
                                    {/* Budget bar */}
                                    <div className="mt-2 flex items-center gap-3">
                                        <div className="h-1.5 flex-1 rounded-full bg-muted">
                                            <div
                                                className="h-1.5 rounded-full transition-all"
                                                style={{
                                                    width: `${Math.min(100, ag.budget_utilisation_percent)}%`,
                                                    backgroundColor: ag.budget_utilisation_percent > 90 ? OPS_COLORS.danger : ag.budget_utilisation_percent > 70 ? OPS_COLORS.warning : OPS_COLORS.success,
                                                }}
                                            />
                                        </div>
                                        <span className="text-[10px] font-medium tabular-nums">
                                            {formatCurrency(ag.budget_used)} / {formatCurrency(ag.total_budget)} ({ag.budget_utilisation_percent}%)
                                        </span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0"><Link href={`/operations/service-agreements/${ag.id}`}><Eye className="h-3.5 w-3.5" /></Link></Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0"><Link href={`/operations/service-agreements/${ag.id}/edit`}><Pencil className="h-3.5 w-3.5" /></Link></Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(agreements?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(agreements?.links ?? []).map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
