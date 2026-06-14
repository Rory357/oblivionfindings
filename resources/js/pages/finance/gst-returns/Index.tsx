import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { TaxTabsFooter } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FileText, Plus, DollarSign, TrendingUp, TrendingDown, Calculator } from 'lucide-react';
import { useMemo } from 'react';

type GstReturn = {
    id: number;
    period_start: string;
    period_end: string;
    filing_frequency: string;
    basis: string;
    total_sales: string;
    total_gst_collected: string;
    total_purchases: string;
    total_gst_paid: string;
    gst_payable: string;
    status: string;
    ird_period: string;
    filed_at: string | null;
};

type PaginatedData = {
    data: GstReturn[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
};

type PageProps = {
    gstReturns: PaginatedData;
    filters: {
        status?: string;
        year?: string;
    };
};

const formatNZD = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const frequencyLabels: Record<string, string> = {
    monthly: 'Monthly',
    two_monthly: 'Two-Monthly',
    six_monthly: 'Six-Monthly',
};

const basisLabels: Record<string, string> = {
    invoice: 'Invoice',
    payments: 'Payments',
    hybrid: 'Hybrid',
};

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground border-border' },
    filed: { label: 'Filed', className: 'bg-status-success-bg text-status-success border-status-success/30' },
    amended: { label: 'Amended', className: 'bg-status-info-bg text-status-info border-status-info/30' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'GST Returns', href: '/finance/gst-returns' },
];

export default function GstReturnsIndex({ gstReturns, filters }: PageProps) {
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - i);

    const kpis = useMemo(() => {
        const data = gstReturns.data;
        const totalCollected = data.reduce((sum, r) => sum + Number(r.total_gst_collected), 0);
        const totalPaid = data.reduce((sum, r) => sum + Number(r.total_gst_paid), 0);
        const totalPayable = data.reduce((sum, r) => sum + Number(r.gst_payable), 0);
        const draftCount = data.filter((r) => r.status === 'draft').length;
        return { totalCollected, totalPaid, totalPayable, draftCount };
    }, [gstReturns.data]);

    function applyFilter(key: string, value: string | undefined) {
        const params: Record<string, string> = { ...filters };
        if (value && value !== 'all') {
            params[key] = value;
        } else {
            delete params[key];
        }
        router.get('/finance/gst-returns', params, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="GST Returns" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Calculator}
                        title="GST Returns"
                        description="Manage and file GST returns with IRD"
                        stats={[
                            { label: 'GST collected', value: formatNZD(kpis.totalCollected) },
                            { label: 'GST paid', value: formatNZD(kpis.totalPaid) },
                            { label: 'Net payable', value: formatNZD(Math.abs(kpis.totalPayable)) },
                            { label: 'Drafts', value: kpis.draftCount },
                        ]}
                        actions={
                            <Link href={'/finance/gst-returns/prepare'}>
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Prepare Return
                                </Button>
                            </Link>
                        }
                        footer={<TaxTabsFooter active="gst-returns" />}
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success p-2">
                                    <TrendingUp className="h-5 w-5 text-status-success" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">GST Collected</p>
                                    <p className="text-xl font-bold font-mono tabular-nums">{formatNZD(kpis.totalCollected)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info p-2">
                                    <TrendingDown className="h-5 w-5 text-status-info" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">GST Paid</p>
                                    <p className="text-xl font-bold font-mono tabular-nums">{formatNZD(kpis.totalPaid)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2">
                                    <DollarSign className="h-5 w-5 text-status-warning" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Net Payable</p>
                                    <p className={`text-xl font-bold font-mono tabular-nums ${kpis.totalPayable < 0 ? 'text-status-success' : ''}`}>
                                        {formatNZD(Math.abs(kpis.totalPayable))}
                                        {kpis.totalPayable < 0 ? ' (Refund)' : ''}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-muted-foreground/80/10 p-2">
                                    <FileText className="h-5 w-5 text-muted-foreground" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Draft Returns</p>
                                    <p className="text-xl font-bold">{kpis.draftCount}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Returns</CardTitle>
                            </div>
                            <div className="flex items-center gap-3">
                                <Select
                                    value={filters.status ?? 'all'}
                                    onValueChange={(v) => applyFilter('status', v)}
                                >
                                    <SelectTrigger className="w-[140px]">
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="draft">Draft</SelectItem>
                                        <SelectItem value="filed">Filed</SelectItem>
                                        <SelectItem value="amended">Amended</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={filters.year ?? 'all'}
                                    onValueChange={(v) => applyFilter('year', v)}
                                >
                                    <SelectTrigger className="w-[120px]">
                                        <SelectValue placeholder="Year" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Years</SelectItem>
                                        {years.map((y) => (
                                            <SelectItem key={y} value={String(y)}>
                                                {y}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Period</th>
                                        <th className="pb-3 pr-4 font-medium">Frequency</th>
                                        <th className="pb-3 pr-4 font-medium">Basis</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Total Sales</th>
                                        <th className="pb-3 pr-4 font-medium text-right">GST Collected</th>
                                        <th className="pb-3 pr-4 font-medium text-right">GST Paid</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Net Payable</th>
                                        <th className="pb-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gstReturns.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No GST returns found. Prepare your first return to get started.
                                            </td>
                                        </tr>
                                    ) : (
                                        gstReturns.data.map((gstReturn) => {
                                            const payable = Number(gstReturn.gst_payable);
                                            const isRefund = payable < 0;
                                            const status = statusConfig[gstReturn.status] ?? statusConfig.draft;

                                            return (
                                                <tr
                                                    key={gstReturn.id}
                                                    className="border-b last:border-0 hover:bg-muted/50 cursor-pointer"
                                                    onClick={() =>
                                                        router.visit(
                                                            `/finance/gst-returns/${gstReturn.id}`,
                                                        )
                                                    }
                                                >
                                                    <td className="py-3 pr-4">
                                                        <div className="font-medium">
                                                            {formatDate(gstReturn.period_start)} &ndash;{' '}
                                                            {formatDate(gstReturn.period_end)}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            IRD Period: {gstReturn.ird_period}
                                                        </div>
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {frequencyLabels[gstReturn.filing_frequency] ??
                                                            gstReturn.filing_frequency}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {basisLabels[gstReturn.basis] ?? gstReturn.basis}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatNZD(gstReturn.total_sales)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatNZD(gstReturn.total_gst_collected)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatNZD(gstReturn.total_gst_paid)}
                                                    </td>
                                                    <td
                                                        className={`py-3 pr-4 text-right font-mono font-semibold tabular-nums ${
                                                            isRefund ? 'text-status-success' : 'text-destructive'
                                                        }`}
                                                    >
                                                        {isRefund ? '(' : ''}
                                                        {formatNZD(Math.abs(payable))}
                                                        {isRefund ? ')' : ''}
                                                    </td>
                                                    <td className="py-3">
                                                        <Badge variant="outline" className={status.className}>
                                                            {status.label}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {gstReturns.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {gstReturns.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
