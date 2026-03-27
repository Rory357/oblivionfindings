import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FileText, Plus } from 'lucide-react';

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
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-700 border-gray-300' },
    filed: { label: 'Filed', className: 'bg-green-100 text-green-700 border-green-300' },
    amended: { label: 'Amended', className: 'bg-blue-100 text-blue-700 border-blue-300' },
};

export default function GstReturnsIndex({ gstReturns, filters }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: route('finance.dashboard') },
        { title: 'GST Returns', href: route('finance.gst-returns.index') },
    ];

    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - i);

    function applyFilter(key: string, value: string | undefined) {
        const params: Record<string, string> = { ...filters };
        if (value && value !== 'all') {
            params[key] = value;
        } else {
            delete params[key];
        }
        router.get(route('finance.gst-returns.index'), params, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="GST Returns" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">GST Returns</h1>
                        <p className="text-muted-foreground">Manage and file GST returns with IRD</p>
                    </div>
                    <Link href={route('finance.gst-returns.prepare')}>
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            Prepare Return
                        </Button>
                    </Link>
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
                                                            route('finance.gst-returns.show', gstReturn.id),
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
                                                            isRefund ? 'text-green-600' : 'text-red-600'
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
            </div>
        </AppLayout>
    );
}
