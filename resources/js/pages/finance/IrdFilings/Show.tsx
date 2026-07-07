import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertCircle, CheckCircle, FileText, Send, Shield } from 'lucide-react';
import { ConfirmDialog } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';

type GstReturn = {
    id: number;
    period_start: string;
    period_end: string;
    gst_payable: string;
    status: string;
    ird_period: string;
};

type Filing = {
    id: number;
    filing_type: string;
    period_from: string;
    period_to: string;
    filing_data: Record<string, string>;
    total_amount: string;
    status: string;
    submitted_at: string | null;
    ird_reference: string | null;
    ird_response: Record<string, string> | null;
    error_message: string | null;
    gst_return: GstReturn | null;
    created_by: { id: number; name: string } | null;
    created_at: string;
};

type PageProps = {
    filing: Filing;
};

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

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

const filingTypeLabels: Record<string, string> = {
    gst: 'GST Return',
    payday: 'Payday Filing',
    rlwt: 'RLWT',
    rwt: 'RWT',
    aim: 'AIM',
    ir3: 'IR3',
    ir4: 'IR4',
    ir7: 'IR7',
};

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-muted-foreground border-border' },
    validated: { label: 'Validated', className: 'bg-status-info-bg text-status-info border-status-info/30' },
    submitted: { label: 'Submitted', className: 'bg-status-warning-bg text-status-warning border-status-warning/30' },
    accepted: { label: 'Accepted', className: 'bg-status-success-bg text-status-success border-status-success/30' },
    rejected: { label: 'Rejected', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' },
    error: { label: 'Error', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' },
};

const filingDataLabels: Record<string, string> = {
    return_type: 'Return Type',
    period_from: 'Period From',
    period_to: 'Period To',
    ird_period: 'IRD Period',
    filing_frequency: 'Filing Frequency',
    accounting_basis: 'Accounting Basis',
    total_sales: 'Total Sales & Income (Box 5)',
    zero_rated_supplies: 'Zero-Rated Supplies (Box 6)',
    taxable_sales: 'Taxable Sales (Box 7)',
    gst_collected: 'GST on Sales (Box 8)',
    output_adjustments: 'Output Adjustments (Box 9)',
    total_gst_collected: 'Total GST Collected (Box 10)',
    total_purchases: 'Total Purchases & Expenses (Box 11)',
    gst_paid: 'GST on Purchases (Box 12)',
    input_adjustments: 'Input Adjustments (Box 13)',
    total_gst_credit: 'Total GST Credit (Box 14)',
    gst_payable: 'GST Payable/Refundable (Box 15)',
};

export default function IrdFilingShow({ filing }: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'IRD E-Filing', href: '/finance/ird-filings' },
        {
            title: `${filingTypeLabels[filing.filing_type]} - ${formatDate(filing.period_to)}`,
            href: `/finance/ird-filings/${filing.id}`,
        },
    ];

    const status = statusConfig[filing.status] ?? statusConfig.draft;
    const canValidate = filing.status === 'draft';
    const canSubmit = filing.status === 'validated' || filing.status === 'error';
    const amount = Number(filing.total_amount);
    const isRefund = amount < 0;

    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    function handleValidate() {
        router.post(`/finance/ird-filings/${filing.id}/validate`);
    }

    function handleSubmit() {
        router.post(`/finance/ird-filings/${filing.id}/submit`, {}, {
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: () => setConfirmSubmit(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`IRD Filing - ${filingTypeLabels[filing.filing_type]}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/ird-filings"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {filingTypeLabels[filing.filing_type] ?? filing.filing_type}
                                <Badge variant="outline" className={status.className}>
                                    {status.label}
                                </Badge>
                            </span>
                        }
                        description={
                            <>
                                {formatDate(filing.period_from)} &ndash; {formatDate(filing.period_to)}
                                {filing.created_by && (
                                    <span className="mt-1 block text-sm">
                                        Created by {filing.created_by.name}
                                    </span>
                                )}
                            </>
                        }
                        actions={
                            <>
                                {canValidate && (
                                    <Button variant="outline" onClick={handleValidate}>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Validate
                                    </Button>
                                )}
                                {canSubmit && (
                                    <Button onClick={() => setConfirmSubmit(true)}>
                                        <Send className="mr-2 h-4 w-4" />
                                        Submit to IRD
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                {/* Error Message */}
                {filing.error_message && (
                    <Card className="border-destructive/50 bg-destructive/5">
                        <CardContent className="flex items-start gap-3 py-4">
                            <AlertCircle className="h-5 w-5 text-destructive shrink-0 mt-0.5" />
                            <div>
                                <p className="font-medium text-destructive">Submission Error</p>
                                <p className="text-sm text-destructive/80">{filing.error_message}</p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Submission Info */}
                {filing.submitted_at && (
                    <Card className="border-status-success/30 bg-status-success">
                        <CardContent className="flex items-start gap-3 py-4">
                            <CheckCircle className="h-5 w-5 text-status-success shrink-0 mt-0.5" />
                            <div>
                                <p className="font-medium text-status-success dark:text-status-success">Submitted to IRD</p>
                                <p className="text-sm text-status-success dark:text-status-success">
                                    Submitted on {formatDateTime(filing.submitted_at)}
                                    {filing.ird_reference && (
                                        <> | Reference: <span className="font-mono">{filing.ird_reference}</span></>
                                    )}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Summary */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Shield className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Filing Summary</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-6 sm:grid-cols-4">
                            <div>
                                <p className="text-sm text-muted-foreground">Filing Type</p>
                                <p className="font-medium">
                                    {filingTypeLabels[filing.filing_type] ?? filing.filing_type}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Period</p>
                                <p className="font-medium">
                                    {formatDate(filing.period_from)} &ndash; {formatDate(filing.period_to)}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Amount</p>
                                <p className={`font-mono font-semibold tabular-nums ${isRefund ? 'text-status-success' : 'text-status-critical'}`}>
                                    {formatCurrency(Math.abs(amount))}
                                    {isRefund ? ' (Refund)' : ''}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">IRD Reference</p>
                                <p className="font-mono">{filing.ird_reference ?? 'Not yet submitted'}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Filing Data */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <FileText className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Filing Data</CardTitle>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Data that will be submitted to IRD Gateway Services
                        </p>
                    </CardHeader>
                    <CardContent>
                        {filing.filing_data ? (
                            <div className="space-y-6">
                                {/* Monetary Fields */}
                                <div>
                                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                        Return Data
                                    </h3>
                                    <div className="space-y-2">
                                        {Object.entries(filing.filing_data)
                                            .filter(([key]) => key in filingDataLabels)
                                            .map(([key, value]) => {
                                                const isMonetary = [
                                                    'total_sales', 'zero_rated_supplies', 'taxable_sales',
                                                    'gst_collected', 'output_adjustments', 'total_gst_collected',
                                                    'total_purchases', 'gst_paid', 'input_adjustments',
                                                    'total_gst_credit', 'gst_payable',
                                                ].includes(key);

                                                const isHighlight = key === 'gst_payable';

                                                return (
                                                    <div
                                                        key={key}
                                                        className={`flex items-center justify-between rounded-lg border p-3 ${
                                                            isHighlight ? 'border-primary bg-primary/5' : ''
                                                        }`}
                                                    >
                                                        <span className="text-sm text-foreground">
                                                            {filingDataLabels[key] ?? key}
                                                        </span>
                                                        <span className={`font-mono text-sm tabular-nums ${
                                                            isHighlight ? 'font-semibold' : ''
                                                        } ${
                                                            isMonetary && isHighlight
                                                                ? Number(value) >= 0
                                                                    ? 'text-status-critical'
                                                                    : 'text-status-success'
                                                                : ''
                                                        }`}>
                                                            {isMonetary ? formatCurrency(Number(value)) : String(value)}
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="py-4 text-center text-muted-foreground">No filing data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* IRD Response */}
                {filing.ird_response && (
                    <Card>
                        <CardHeader>
                            <CardTitle>IRD Response</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {Object.entries(filing.ird_response).map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="flex items-center justify-between rounded-lg border p-3"
                                    >
                                        <span className="text-sm capitalize text-foreground">
                                            {key.replace(/_/g, ' ')}
                                        </span>
                                        <span className="font-mono text-sm">{String(value)}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Linked GST Return */}
                {filing.gst_return && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Linked GST Return</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="font-medium">
                                        Period: {formatDate(filing.gst_return.period_start)} &ndash;{' '}
                                        {formatDate(filing.gst_return.period_end)}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        IRD Period: {filing.gst_return.ird_period} | GST Payable:{' '}
                                        {formatCurrency(Number(filing.gst_return.gst_payable))}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.visit(`/finance/gst-returns/${filing.gst_return!.id}`)
                                    }
                                >
                                    View Return
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            <ConfirmDialog
                open={confirmSubmit}
                onOpenChange={setConfirmSubmit}
                title="Submit filing to IRD?"
                description="This transmits the filing data to Inland Revenue. Only simulated submissions are made unless a live IRD gateway is configured."
                confirmLabel="Submit to IRD"
                processing={submitting}
                onConfirm={handleSubmit}
            />
        </AppLayout>
    );
}
