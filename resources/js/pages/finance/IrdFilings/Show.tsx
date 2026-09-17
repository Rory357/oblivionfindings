import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
} from '@/components/finance';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle,
    FileText,
    Landmark,
    Send,
    Shield,
} from 'lucide-react';
import { useState } from 'react';

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

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FILING_TYPE_LABELS: Record<string, string> = {
    gst: 'GST return',
    payday: 'Payday filing',
    rlwt: 'RLWT',
    rwt: 'RWT',
    aim: 'AIM',
    ir3: 'IR3',
    ir4: 'IR4',
    ir7: 'IR7',
};

const FILING_DATA_LABELS: Record<string, string> = {
    return_type: 'Return type',
    period_from: 'Period from',
    period_to: 'Period to',
    ird_period: 'IRD period',
    filing_frequency: 'Filing frequency',
    accounting_basis: 'Accounting basis',
    total_sales: 'Total sales and income (box 5)',
    zero_rated_supplies: 'Zero-rated supplies (box 6)',
    taxable_sales: 'Taxable sales (box 7)',
    gst_collected: 'GST on sales (box 8)',
    output_adjustments: 'Output adjustments (box 9)',
    total_gst_collected: 'Total GST collected (box 10)',
    total_purchases: 'Total purchases and expenses (box 11)',
    gst_paid: 'GST on purchases (box 12)',
    input_adjustments: 'Input adjustments (box 13)',
    total_gst_credit: 'Total GST credit (box 14)',
    gst_payable: 'GST payable or refundable (box 15)',
};

const MONETARY_KEYS = [
    'total_sales',
    'zero_rated_supplies',
    'taxable_sales',
    'gst_collected',
    'output_adjustments',
    'total_gst_collected',
    'total_purchases',
    'gst_paid',
    'input_adjustments',
    'total_gst_credit',
    'gst_payable',
];

const STATUS_CHIP: Record<string, StatusVariant> = {
    draft: 'neutral',
    validated: 'info',
    submitted: 'warning',
    accepted: 'success',
    rejected: 'critical',
    error: 'critical',
};

export default function IrdFilingShow({ filing }: PageProps) {
    const typeLabel =
        FILING_TYPE_LABELS[filing.filing_type] ?? filing.filing_type;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Tax & compliance', href: '/finance/tax' },
        { title: 'IRD filings', href: '/finance/ird-filings' },
        {
            title: `${typeLabel} — ${shortDate(filing.period_to)}`,
            href: `/finance/ird-filings/${filing.id}`,
        },
    ];

    const canValidate = filing.status === 'draft';
    const canSubmit =
        filing.status === 'validated' || filing.status === 'error';
    const amount = Number(filing.total_amount);
    const isRefund = amount < 0;
    // `simulated` arrives as a JSON boolean inside ird_response, so compare loosely.
    const rawSimulated: unknown = filing.ird_response?.simulated;
    const isSimulated =
        rawSimulated === true ||
        rawSimulated === 'true' ||
        rawSimulated === 1 ||
        rawSimulated === '1';

    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const handleValidate = () => {
        router.post(`/finance/ird-filings/${filing.id}/validate`);
    };

    const handleSubmit = () => {
        router.post(
            `/finance/ird-filings/${filing.id}/submit`,
            {},
            {
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => setConfirmSubmit(false),
            },
        );
    };

    const gstReturnHref = filing.gst_return
        ? `/finance/gst-returns/${filing.gst_return.id}`
        : '/finance/gst-returns';

    const moneyFromData = (key: string) => {
        const raw = filing.filing_data?.[key];
        return raw == null || raw === '' ? null : Number(raw);
    };

    const totalSales = moneyFromData('total_sales');
    const gstCollected = moneyFromData('total_gst_collected');
    const gstCredit = moneyFromData('total_gst_credit');

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/ird-filings"
            icon={Landmark}
            title={typeLabel}
            titleChip={
                <PageHeaderStatusChip
                    variant={STATUS_CHIP[filing.status] ?? 'neutral'}
                >
                    {filing.status.charAt(0).toUpperCase() +
                        filing.status.slice(1)}
                </PageHeaderStatusChip>
            }
            subline={[
                `${shortDate(filing.period_from)} – ${shortDate(filing.period_to)}`,
                filing.ird_reference
                    ? `Reference ${filing.ird_reference}`
                    : 'Not yet submitted',
                filing.created_by
                    ? `Created by ${filing.created_by.name}`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canValidate ? (
                        <PageHeaderGlassButton
                            icon={CheckCircle}
                            onClick={handleValidate}
                        >
                            Validate
                        </PageHeaderGlassButton>
                    ) : null}
                    {canSubmit ? (
                        <PageHeaderPrimaryButton
                            icon={Send}
                            onClick={() => setConfirmSubmit(true)}
                        >
                            Submit to IRD
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label={isRefund ? 'Refund due' : 'Amount owing'}
                        tone={isRefund ? 'success' : 'warning'}
                        href={gstReturnHref}
                        ariaLabel="View the source GST return"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(Math.abs(amount))}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {isRefund ? 'due back from IRD' : 'payable to IRD'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    {totalSales !== null ? (
                        <PageHeaderMeterBlock
                            label="Total sales"
                            href={gstReturnHref}
                            ariaLabel="View the source GST return"
                        >
                            <PageHeaderMeterBig>
                                {formatMoney(totalSales)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                declared for the period
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}

                    {gstCollected !== null ? (
                        <PageHeaderMeterBlock
                            label="GST collected"
                            tone="success"
                            href={gstReturnHref}
                            ariaLabel="View the source GST return"
                        >
                            <PageHeaderMeterBig>
                                {formatMoney(gstCollected)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                output tax on sales
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}

                    {gstCredit !== null ? (
                        <PageHeaderMeterBlock
                            label="GST credit"
                            href={gstReturnHref}
                            ariaLabel="View the source GST return"
                        >
                            <PageHeaderMeterBig>
                                {formatMoney(gstCredit)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                input tax on purchases
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}

                    <PageHeaderMeterBlock
                        label="Submission"
                        href="/finance/ird-filings"
                        ariaLabel="View every IRD filing"
                    >
                        <PageHeaderMeterBig>
                            {filing.submitted_at ? 'Sent' : 'Not sent'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {filing.submitted_at
                                ? formatDateTime(filing.submitted_at)
                                : 'waiting to be submitted'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`IRD filing — ${typeLabel}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {filing.error_message ? (
                        <Card className="border-status-critical/40">
                            <CardContent className="flex items-start gap-3 py-4">
                                <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-status-critical" />
                                <div>
                                    <p className="font-medium text-status-critical">
                                        Submission problem
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {filing.error_message}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    {filing.submitted_at ? (
                        <Card className="border-status-success/40">
                            <CardContent className="flex items-start gap-3 py-4">
                                <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-status-success" />
                                <div className="flex flex-col gap-1">
                                    <p className="flex items-center gap-2 font-medium">
                                        Sent to IRD
                                        {isSimulated ? (
                                            <StatusBadge
                                                variant="warning"
                                                size="sm"
                                            >
                                                Test filing
                                            </StatusBadge>
                                        ) : null}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Sent{' '}
                                        {formatDateTime(filing.submitted_at)}
                                        {filing.ird_reference
                                            ? ` · reference ${filing.ird_reference}`
                                            : ''}
                                        {isSimulated
                                            ? ' · this was a test filing, not a real one — file it through myIR as well'
                                            : ''}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                <CardTitle className="text-section-title">
                                    Filing data
                                </CardTitle>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                The figures this filing sends to Inland Revenue.
                            </p>
                        </CardHeader>
                        <CardContent>
                            {filing.filing_data ? (
                                <div className="flex flex-col gap-2">
                                    {Object.entries(filing.filing_data)
                                        .filter(
                                            ([key]) =>
                                                key in FILING_DATA_LABELS,
                                        )
                                        .map(([key, value]) => {
                                            const isMonetary =
                                                MONETARY_KEYS.includes(key);
                                            const isHighlight =
                                                key === 'gst_payable';

                                            return (
                                                <div
                                                    key={key}
                                                    className={`flex items-center justify-between rounded-lg border p-3 ${
                                                        isHighlight
                                                            ? 'border-primary bg-primary/5'
                                                            : ''
                                                    }`}
                                                >
                                                    <span className="text-sm text-foreground">
                                                        {FILING_DATA_LABELS[
                                                            key
                                                        ] ?? key}
                                                    </span>
                                                    <span
                                                        className={`text-sm tabular-nums ${
                                                            isHighlight
                                                                ? 'font-semibold'
                                                                : ''
                                                        }`}
                                                    >
                                                        {isMonetary
                                                            ? formatMoney(
                                                                  Number(value),
                                                              )
                                                            : String(value)}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                </div>
                            ) : (
                                <p className="py-4 text-center text-muted-foreground">
                                    No filing data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {filing.ird_response ? (
                        <Card>
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <Shield className="h-5 w-5 text-muted-foreground" />
                                    <CardTitle className="text-section-title">
                                        IRD response
                                    </CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col gap-2">
                                    {Object.entries(filing.ird_response).map(
                                        ([key, value]) => (
                                            <div
                                                key={key}
                                                className="flex items-center justify-between rounded-lg border p-3"
                                            >
                                                <span className="text-sm text-foreground first-letter:uppercase">
                                                    {key.replace(/_/g, ' ')}
                                                </span>
                                                <span className="text-sm">
                                                    {String(value)}
                                                </span>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    {filing.gst_return ? (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Linked GST return
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {shortDate(
                                                filing.gst_return.period_start,
                                            )}{' '}
                                            –{' '}
                                            {shortDate(
                                                filing.gst_return.period_end,
                                            )}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            IRD period{' '}
                                            {filing.gst_return.ird_period} · GST
                                            payable{' '}
                                            {formatMoney(
                                                filing.gst_return.gst_payable,
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.visit(gstReturnHref)
                                        }
                                    >
                                        Open return
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={confirmSubmit}
                onClose={() => setConfirmSubmit(false)}
                title="Send this filing to IRD?"
                description="This sends the filing figures to Inland Revenue. Until a live IRD connection is set up it records a test filing instead, so you still need to file through myIR."
                confirmText="Send to IRD"
                processing={submitting}
                onConfirm={handleSubmit}
            />
        </AppLayout>
    );
}
