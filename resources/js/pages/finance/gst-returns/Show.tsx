import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCircle, FileText, Printer } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';

type TaxRate = {
    id: number;
    code: string;
    name: string;
    rate: string;
};

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
};

type Journal = {
    id: number;
    journal_number: string;
    journal_date: string;
};

type JournalLine = {
    journal: Journal | null;
};

type GstReturnLine = {
    id: number;
    journal_line_id: number;
    account_id: number;
    description: string;
    net_amount: string;
    gst_amount: string;
    tax_rate_id: number;
    account: Account | null;
    tax_rate: TaxRate | null;
    journal_line: JournalLine | null;
};

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
    adjustments: string;
    status: string;
    ird_period: string;
    filed_at: string | null;
    filed_by: { id: number; name: string } | null;
    created_by: { id: number; name: string } | null;
    lines: GstReturnLine[];
};

type TaxRateBreakdown = {
    tax_rate_id: number;
    name: string;
    code: string;
    rate: string;
    net_amount: string;
    gst_amount: string;
    line_count: number;
};

type Summary = {
    total_sales: number;
    total_gst_collected: number;
    total_purchases: number;
    total_gst_paid: number;
    gst_payable: number;
    adjustments: number;
    net_gst: number;
    is_refund: boolean;
    breakdown_by_tax_rate: TaxRateBreakdown[];
};

type IrdFormData = {
    period_start: string;
    period_end: string;
    ird_period: string;
    filing_frequency: string;
    basis: string;
    box_5: number;
    box_5_label: string;
    box_6: number;
    box_6_label: string;
    box_7: number;
    box_7_label: string;
    box_8: number;
    box_8_label: string;
    box_9: number;
    box_9_label: string;
    box_11: number;
    box_11_label: string;
    box_12: number;
    box_12_label: string;
    box_13: number;
    box_13_label: string;
};

type PageProps = {
    gstReturn: GstReturn;
    summary: Summary;
    irdFormData: IrdFormData;
};

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const formatDateTime = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground border-border' },
    filed: { label: 'Filed', className: 'bg-status-success-bg text-status-success border-status-success/30' },
    amended: { label: 'Amended', className: 'bg-status-info-bg text-status-info border-status-info/30' },
};

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

function IrdFormBox({
    boxNumber,
    label,
    amount,
    highlight = false,
}: {
    boxNumber: string;
    label: string;
    amount: number;
    highlight?: boolean;
}) {
    return (
        <div
            className={`flex items-center justify-between rounded-lg border p-3 ${
                highlight ? 'border-primary bg-primary/5' : ''
            }`}
        >
            <div className="flex items-center gap-3">
                <span className="flex h-8 w-8 items-center justify-center rounded bg-muted text-xs font-bold">
                    {boxNumber}
                </span>
                <span className="text-sm">{label}</span>
            </div>
            <span
                className={`font-mono text-sm font-semibold tabular-nums ${
                    highlight
                        ? amount >= 0
                            ? 'text-destructive'
                            : 'text-status-success'
                        : ''
                }`}
            >
                {formatNZD(Math.abs(amount))}
                {highlight && amount < 0 ? ' (Refund)' : ''}
            </span>
        </div>
    );
}

export default function GstReturnShow({ gstReturn, summary, irdFormData }: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'GST Returns', href: '/finance/gst-returns' },
        { title: `Period ending ${formatDate(gstReturn.period_end)}`, href: `/finance/gst-returns/${gstReturn.id}` },
    ];

    const status = statusConfig[gstReturn.status] ?? statusConfig.draft;
    const isDraft = gstReturn.status === 'draft';

    function handleFile() {
        if (confirm('Are you sure you want to mark this return as filed? This action cannot be undone.')) {
            router.post(`/finance/gst-returns/${gstReturn.id}/file`);
        }
    }

    function handlePrint() {
        window.print();
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`GST Return - ${irdFormData.ird_period}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/gst-returns"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                GST Return
                                <Badge variant="outline" className={status.className}>
                                    {status.label}
                                </Badge>
                            </span>
                        }
                        description={
                            <>
                                {formatDate(gstReturn.period_start)} &ndash; {formatDate(gstReturn.period_end)}
                                {' | '}
                                {frequencyLabels[gstReturn.filing_frequency]} |{' '}
                                {basisLabels[gstReturn.basis]} Basis | IRD Period: {gstReturn.ird_period}
                                {gstReturn.filed_at && gstReturn.filed_by && (
                                    <span className="mt-1 block text-sm">
                                        Filed on {formatDateTime(gstReturn.filed_at)} by {gstReturn.filed_by.name}
                                    </span>
                                )}
                            </>
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={handlePrint}>
                                    <Printer className="mr-2 h-4 w-4" />
                                    Print
                                </Button>
                                {isDraft && (
                                    <Button onClick={handleFile}>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Mark as Filed
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                {/* GST101A Form Layout */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <FileText className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>IRD GST101A Summary</CardTitle>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Values for completing your GST return on myIR
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-6">
                            {/* Sales Section */}
                            <div>
                                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                    Sales and Income
                                </h3>
                                <div className="space-y-2">
                                    <IrdFormBox boxNumber="5" label={irdFormData.box_5_label} amount={irdFormData.box_5} />
                                    <IrdFormBox boxNumber="6" label={irdFormData.box_6_label} amount={irdFormData.box_6} />
                                    <IrdFormBox boxNumber="7" label={irdFormData.box_7_label} amount={irdFormData.box_7} />
                                    <IrdFormBox boxNumber="8" label={irdFormData.box_8_label} amount={irdFormData.box_8} />
                                    <IrdFormBox boxNumber="9" label={irdFormData.box_9_label} amount={irdFormData.box_9} />
                                </div>
                            </div>

                            {/* Purchases Section */}
                            <div>
                                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                    Purchases and Expenses
                                </h3>
                                <div className="space-y-2">
                                    <IrdFormBox boxNumber="11" label={irdFormData.box_11_label} amount={irdFormData.box_11} />
                                    <IrdFormBox boxNumber="12" label={irdFormData.box_12_label} amount={irdFormData.box_12} />
                                </div>
                            </div>

                            {/* Net GST */}
                            <div>
                                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                    Net GST
                                </h3>
                                <IrdFormBox
                                    boxNumber="13"
                                    label={irdFormData.box_13_label}
                                    amount={irdFormData.box_13}
                                    highlight
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Breakdown by Tax Rate */}
                {summary.breakdown_by_tax_rate.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Breakdown by Tax Rate</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Tax Rate</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Rate</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Net Amount</th>
                                        <th className="pb-3 pr-4 font-medium text-right">GST Amount</th>
                                        <th className="pb-3 font-medium text-right">Lines</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {summary.breakdown_by_tax_rate.map((item) => (
                                        <tr key={item.tax_rate_id} className="border-b last:border-0">
                                            <td className="py-3 pr-4">
                                                <span className="font-medium">{item.name}</span>
                                                {item.code && (
                                                    <span className="ml-2 text-muted-foreground">({item.code})</span>
                                                )}
                                            </td>
                                            <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                {Number(item.rate)}%
                                            </td>
                                            <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                {formatNZD(item.net_amount)}
                                            </td>
                                            <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                {formatNZD(item.gst_amount)}
                                            </td>
                                            <td className="py-3 text-right">{item.line_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {/* Detail Lines */}
                <Card>
                    <CardHeader>
                        <CardTitle>Detail Lines ({gstReturn.lines.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {gstReturn.lines.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No journal lines found for this period.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-3 pr-4 font-medium">Date</th>
                                            <th className="pb-3 pr-4 font-medium">Journal #</th>
                                            <th className="pb-3 pr-4 font-medium">Account</th>
                                            <th className="pb-3 pr-4 font-medium">Description</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Net Amount</th>
                                            <th className="pb-3 pr-4 font-medium text-right">GST Amount</th>
                                            <th className="pb-3 font-medium">Tax Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {gstReturn.lines.map((line) => (
                                            <tr key={line.id} className="border-b last:border-0">
                                                <td className="py-2 pr-4 whitespace-nowrap">
                                                    {line.journal_line?.journal?.journal_date
                                                        ? formatDate(line.journal_line.journal.journal_date)
                                                        : '-'}
                                                </td>
                                                <td className="py-2 pr-4 font-mono text-xs">
                                                    {line.journal_line?.journal?.journal_number ?? '-'}
                                                </td>
                                                <td className="py-2 pr-4 whitespace-nowrap">
                                                    {line.account && (
                                                        <span>
                                                            <span className="font-mono text-muted-foreground">
                                                                {line.account.code}
                                                            </span>{' '}
                                                            {line.account.name}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-4 max-w-[200px] truncate">
                                                    {line.description || '-'}
                                                </td>
                                                <td className="py-2 pr-4 text-right font-mono tabular-nums">
                                                    {formatNZD(line.net_amount)}
                                                </td>
                                                <td className="py-2 pr-4 text-right font-mono tabular-nums">
                                                    {formatNZD(line.gst_amount)}
                                                </td>
                                                <td className="py-2 text-xs">
                                                    {line.tax_rate
                                                        ? `${line.tax_rate.name} (${Number(line.tax_rate.rate)}%)`
                                                        : '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
