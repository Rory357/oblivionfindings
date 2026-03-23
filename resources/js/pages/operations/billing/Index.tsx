import { DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { ArrowRight, DollarSign, FileText, Receipt, Search } from 'lucide-react';

const ANY = '__ANY__';

type BillingEntry = {
    id: number;
    service_date: string;
    hours: number;
    rate: number;
    amount: number;
    rate_type: string;
    status: string;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    staff: { id: number; name: string } | null;
    service_agreement: { id: number; title: string } | null;
};

type Props = {
    stats: {
        billed_this_month: number;
        outstanding: number;
        paid_this_month: number;
        pending_count: number;
    };
    entries: {
        data: BillingEntry[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    status_breakdown: Record<string, number>;
    filters: { status?: string; client_id?: string; q?: string };
};

const STATUS_COLORS: Record<string, string> = {
    pending: OPS_COLORS.warning,
    approved: OPS_COLORS.primary,
    billed: OPS_COLORS.accent,
    paid: OPS_COLORS.success,
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    approved: 'secondary',
    billed: 'default',
    paid: 'default',
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(n);
}

function formatDate(d: string): string {
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function BillingIndex({ stats = {} as any, entries = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, status_breakdown = {} as any, filters = {} as any }: Props) {
    const s = stats ?? { billed_this_month: 0, outstanding: 0, paid_this_month: 0, pending_count: 0 };

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/billing', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Billing" />
            <PageHeader title="Billing" description="Manage billing entries, revenue tracking, and payment status." backHref="/operations" />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Billed This Month" value={formatCurrency(s.billed_this_month)} icon={DollarSign} color="indigo" />
                    <OpsStatCard label="Outstanding" value={formatCurrency(s.outstanding)} icon={Receipt} color="amber" />
                    <OpsStatCard label="Paid This Month" value={formatCurrency(s.paid_this_month)} icon={DollarSign} color="emerald" />
                    <OpsStatCard label="Pending Entries" value={s.pending_count} icon={FileText} color="blue" href="/operations/billing?status=pending" />
                </div>

                {/* Charts + Filters */}
                <div className="mt-6 grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Billing Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-center gap-4">
                                <DonutChart
                                    segments={Object.entries(status_breakdown ?? {}).map(([status, count]) => ({
                                        label: status, value: count, color: STATUS_COLORS[status] ?? OPS_COLORS.muted,
                                    }))}
                                    centerValue={Object.values(status_breakdown ?? {}).reduce((a, b) => a + b, 0)}
                                    centerLabel="Entries"
                                    size={120}
                                    strokeWidth={14}
                                />
                                <div className="space-y-1.5">
                                    {Object.entries(status_breakdown ?? {}).map(([status, count]) => (
                                        <div key={status} className="flex items-center gap-2">
                                            <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: STATUS_COLORS[status] ?? OPS_COLORS.muted }} />
                                            <span className="text-xs capitalize text-muted-foreground">{status}</span>
                                            <span className="ml-auto text-xs font-medium tabular-nums">{count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Billing Entries</CardTitle>
                            <Button asChild variant="ghost" size="sm" className="h-7 text-xs">
                                <Link href="/operations/invoices">
                                    Invoices <ArrowRight className="ml-1 h-3 w-3" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <div className="relative flex-1">
                                    <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                    <Input placeholder="Search..." className="h-8 pl-8 text-xs" defaultValue={filters?.q ?? ''} onChange={(e) => updateFilters('q', e.target.value || null)} />
                                </div>
                                <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                                    <SelectTrigger className="h-8 w-[110px] text-xs"><SelectValue placeholder="Status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="approved">Approved</SelectItem>
                                        <SelectItem value="billed">Billed</SelectItem>
                                        <SelectItem value="paid">Paid</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                {(entries?.data ?? []).length === 0 && (
                                    <p className="py-6 text-center text-xs text-muted-foreground">No billing entries found. Entries are auto-generated from approved timesheets.</p>
                                )}
                                {(entries?.data ?? []).map((entry) => (
                                    <div key={entry.id} className="flex items-center gap-3 rounded-md border px-3 py-2">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs font-medium">{entry.client ? `${entry.client.first_name} ${entry.client.last_name}` : 'Unknown'}</span>
                                                <Badge variant={STATUS_VARIANTS[entry.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">{entry.status}</Badge>
                                                <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{entry.rate_type}</Badge>
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-3 text-[10px] text-muted-foreground">
                                                <span>{formatDate(entry.service_date)}</span>
                                                <span>{entry.hours}h @ {formatCurrency(entry.rate)}/h</span>
                                                {entry.staff && <span>{entry.staff.name}</span>}
                                            </div>
                                        </div>
                                        <span className="text-sm font-semibold tabular-nums">{formatCurrency(entry.amount)}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Pagination */}
                {(entries?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(entries?.links ?? []).map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
