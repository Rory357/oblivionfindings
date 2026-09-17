import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
} from '@/components/finance';
import {
    EntityTable,
    type EntityTableColumn,
    type EntityTableFooterRow,
    ListCaption,
    type MenuItem,
} from '@/components/lists';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle,
    FileText,
    Percent,
    Printer,
    RotateCcw,
} from 'lucide-react';
import { useState } from 'react';

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
    revision: number;
    supersedes_gst_return_id: number | null;
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
    canManage: boolean;
};

const shortDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

const FREQUENCY_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    two_monthly: 'Two-monthly',
    six_monthly: 'Six-monthly',
};

const BASIS_LABELS: Record<string, string> = {
    invoice: 'Invoice basis',
    payments: 'Payments basis',
    hybrid: 'Hybrid basis',
};

const STATUS_CHIP: Record<string, StatusVariant> = {
    draft: 'neutral',
    filed: 'success',
    amended: 'info',
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
            <span className="text-sm font-semibold tabular-nums">
                {formatMoney(Math.abs(amount))}
                {highlight && amount < 0 ? ' refund' : ''}
            </span>
        </div>
    );
}

export default function GstReturnShow({
    gstReturn,
    summary,
    irdFormData,
    canManage,
}: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Tax & compliance', href: '/finance/tax' },
        { title: 'GST returns', href: '/finance/gst-returns' },
        {
            title: `Period ending ${shortDate(gstReturn.period_end)}`,
            href: `/finance/gst-returns/${gstReturn.id}`,
        },
    ];

    const isDraft = gstReturn.status === 'draft';
    const isFiled = gstReturn.status === 'filed';
    const [fileOpen, setFileOpen] = useState(false);
    const [filing, setFiling] = useState(false);
    const [amendOpen, setAmendOpen] = useState(false);
    const [amending, setAmending] = useState(false);

    const confirmFile = () => {
        router.post(
            `/finance/gst-returns/${gstReturn.id}/file`,
            {},
            {
                onStart: () => setFiling(true),
                onFinish: () => setFiling(false),
                onSuccess: () => setFileOpen(false),
            },
        );
    };

    const confirmAmendment = () => {
        router.post(
            `/finance/gst-returns/${gstReturn.id}/amend`,
            {},
            {
                onStart: () => setAmending(true),
                onFinish: () => setAmending(false),
                onSuccess: () => setAmendOpen(false),
            },
        );
    };

    /* ---------------- Lists ---------------- */

    const noActions = (): MenuItem[] => [];

    const breakdownColumns: EntityTableColumn<TaxRateBreakdown>[] = [
        {
            key: 'rate',
            label: 'Rate',
            width: '110px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">{Number(item.rate)}%</span>
            ),
        },
        {
            key: 'net',
            label: 'Net amount',
            width: '170px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">
                    {formatMoney(item.net_amount)}
                </span>
            ),
        },
        {
            key: 'gst',
            label: 'GST amount',
            width: '170px',
            align: 'right',
            cell: (item) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(item.gst_amount)}
                </span>
            ),
        },
        {
            key: 'lines',
            label: 'Lines',
            width: '100px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">{item.line_count}</span>
            ),
        },
    ];

    const breakdownFooter: EntityTableFooterRow[] = [
        {
            key: 'total',
            label: 'Total',
            tone: 'strong',
            cells: {
                net: (
                    <span className="tabular-nums">
                        {formatMoney(
                            summary.breakdown_by_tax_rate.reduce(
                                (sum, item) => sum + Number(item.net_amount),
                                0,
                            ),
                        )}
                    </span>
                ),
                gst: (
                    <span className="tabular-nums">
                        {formatMoney(
                            summary.breakdown_by_tax_rate.reduce(
                                (sum, item) => sum + Number(item.gst_amount),
                                0,
                            ),
                        )}
                    </span>
                ),
                lines: (
                    <span className="tabular-nums">
                        {summary.breakdown_by_tax_rate.reduce(
                            (sum, item) => sum + item.line_count,
                            0,
                        )}
                    </span>
                ),
            },
        },
    ];

    const lineColumns: EntityTableColumn<GstReturnLine>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '140px',
            cell: (line) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {line.journal_line?.journal?.journal_date
                        ? shortDate(line.journal_line.journal.journal_date)
                        : '—'}
                </span>
            ),
        },
        {
            key: 'account',
            label: 'Account',
            width: '1.2fr',
            cell: (line) =>
                line.account ? (
                    <span className="truncate">
                        <span className="text-muted-foreground">
                            {line.account.code}
                        </span>{' '}
                        {line.account.name}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'net',
            label: 'Net amount',
            width: '150px',
            align: 'right',
            cell: (line) => (
                <span className="tabular-nums">
                    {formatMoney(line.net_amount)}
                </span>
            ),
        },
        {
            key: 'gst',
            label: 'GST amount',
            width: '150px',
            align: 'right',
            cell: (line) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(line.gst_amount)}
                </span>
            ),
        },
        {
            key: 'tax_rate',
            label: 'Tax rate',
            width: '190px',
            cell: (line) => (
                <span className="truncate text-muted-foreground">
                    {line.tax_rate
                        ? `${line.tax_rate.name} (${Number(line.tax_rate.rate)}%)`
                        : '—'}
                </span>
            ),
        },
    ];

    const linesFooter: EntityTableFooterRow[] = [
        {
            key: 'total',
            label: `${gstReturn.lines.length} line${gstReturn.lines.length === 1 ? '' : 's'}`,
            tone: 'strong',
            cells: {
                net: (
                    <span className="tabular-nums">
                        {formatMoney(
                            gstReturn.lines.reduce(
                                (sum, line) => sum + Number(line.net_amount),
                                0,
                            ),
                        )}
                    </span>
                ),
                gst: (
                    <span className="tabular-nums">
                        {formatMoney(
                            gstReturn.lines.reduce(
                                (sum, line) => sum + Number(line.gst_amount),
                                0,
                            ),
                        )}
                    </span>
                ),
            },
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const netGst = summary.net_gst;

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/gst-returns"
            icon={Percent}
            title="GST return"
            titleChip={
                <PageHeaderStatusChip
                    variant={STATUS_CHIP[gstReturn.status] ?? 'neutral'}
                >
                    {gstReturn.status === 'filed'
                        ? 'Filed'
                        : gstReturn.status === 'amended'
                          ? 'Amended'
                          : 'Draft'}
                </PageHeaderStatusChip>
            }
            subline={[
                `${shortDate(gstReturn.period_start)} – ${shortDate(gstReturn.period_end)}`,
                FREQUENCY_LABELS[gstReturn.filing_frequency] ??
                    gstReturn.filing_frequency,
                BASIS_LABELS[gstReturn.basis] ?? gstReturn.basis,
                `IRD period ${gstReturn.ird_period}`,
                `Revision ${gstReturn.revision}`,
                gstReturn.filed_at && gstReturn.filed_by
                    ? `Filed ${formatDateTime(gstReturn.filed_at)} by ${gstReturn.filed_by.name}`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Printer}
                        onClick={() => window.print()}
                    >
                        Print
                    </PageHeaderGlassButton>
                    {canManage && isFiled ? (
                        <PageHeaderGlassButton
                            icon={RotateCcw}
                            onClick={() => setAmendOpen(true)}
                        >
                            Prepare amendment
                        </PageHeaderGlassButton>
                    ) : null}
                    {canManage && isDraft ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setFileOpen(true)}
                        >
                            Mark as filed
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total sales"
                        href="/finance/reports/profit-loss"
                        ariaLabel="View the profit and loss report"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_sales)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            GST-inclusive income for the period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="GST collected"
                        tone="success"
                        href={`/finance/gst-returns/${gstReturn.id}`}
                        ariaLabel="Stay on this return"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_gst_collected)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            output tax on sales
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="GST paid"
                        href={`/finance/gst-returns/${gstReturn.id}`}
                        ariaLabel="Stay on this return"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_gst_paid)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            input tax on purchases
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label={summary.is_refund ? 'Net refund' : 'Net GST'}
                        tone={summary.is_refund ? 'success' : 'warning'}
                        href="/finance/ird-filings"
                        ariaLabel="View IRD filings"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(Math.abs(netGst))}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.is_refund
                                ? 'due back from IRD'
                                : 'owing to IRD'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Detail lines"
                        href={`/finance/gst-returns/${gstReturn.id}`}
                        ariaLabel="Stay on this return"
                    >
                        <PageHeaderMeterBig>
                            {gstReturn.lines.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            journal lines in this period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`GST return — ${irdFormData.ird_period}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileText className="h-5 w-5 text-muted-foreground" />
                                <CardTitle className="text-section-title">
                                    IRD GST101A summary
                                </CardTitle>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Values for completing your GST return on myIR.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col gap-5">
                                <div>
                                    <h3 className="text-caption mb-3 font-semibold tracking-wider uppercase">
                                        Sales and income
                                    </h3>
                                    <div className="flex flex-col gap-2">
                                        <IrdFormBox
                                            boxNumber="5"
                                            label={irdFormData.box_5_label}
                                            amount={irdFormData.box_5}
                                        />
                                        <IrdFormBox
                                            boxNumber="6"
                                            label={irdFormData.box_6_label}
                                            amount={irdFormData.box_6}
                                        />
                                        <IrdFormBox
                                            boxNumber="7"
                                            label={irdFormData.box_7_label}
                                            amount={irdFormData.box_7}
                                        />
                                        <IrdFormBox
                                            boxNumber="8"
                                            label={irdFormData.box_8_label}
                                            amount={irdFormData.box_8}
                                        />
                                        <IrdFormBox
                                            boxNumber="9"
                                            label={irdFormData.box_9_label}
                                            amount={irdFormData.box_9}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-caption mb-3 font-semibold tracking-wider uppercase">
                                        Purchases and expenses
                                    </h3>
                                    <div className="flex flex-col gap-2">
                                        <IrdFormBox
                                            boxNumber="11"
                                            label={irdFormData.box_11_label}
                                            amount={irdFormData.box_11}
                                        />
                                        <IrdFormBox
                                            boxNumber="12"
                                            label={irdFormData.box_12_label}
                                            amount={irdFormData.box_12}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-caption mb-3 font-semibold tracking-wider uppercase">
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

                    {summary.breakdown_by_tax_rate.length > 0 ? (
                        <>
                            <ListCaption
                                title="Breakdown by tax rate"
                                caption={`${summary.breakdown_by_tax_rate.length} rate${
                                    summary.breakdown_by_tax_rate.length === 1
                                        ? ''
                                        : 's'
                                } applied in this period`}
                            />
                            <EntityTable
                                rows={summary.breakdown_by_tax_rate}
                                rowKey={(item) => item.tax_rate_id}
                                identityLabel="Tax rate"
                                minWidth={900}
                                identity={(item) => ({
                                    icon: Percent,
                                    name: item.name,
                                    subline: item.code || undefined,
                                })}
                                columns={breakdownColumns}
                                actionsFor={noActions}
                                footerRows={breakdownFooter}
                            />
                        </>
                    ) : null}

                    <ListCaption
                        title="Detail lines"
                        caption={`${gstReturn.lines.length} journal line${
                            gstReturn.lines.length === 1 ? '' : 's'
                        } in this period`}
                    />
                    {gstReturn.lines.length === 0 ? (
                        <EmptyList
                            icon={BookOpen}
                            itemName="journal line"
                            title="No journal lines for this period"
                            description="Nothing in the ledger falls inside this return's period."
                        />
                    ) : (
                        <EntityTable
                            rows={gstReturn.lines}
                            rowKey={(line) => line.id}
                            identityLabel="Journal"
                            minWidth={1180}
                            identity={(line) => ({
                                icon: BookOpen,
                                name:
                                    line.journal_line?.journal
                                        ?.journal_number ?? 'Unlinked line',
                                subline: line.description || undefined,
                            })}
                            columns={lineColumns}
                            actionsFor={(line) =>
                                line.journal_line?.journal
                                    ? [
                                          {
                                              label: 'Open journal',
                                              icon: BookOpen,
                                              onClick: () =>
                                                  router.visit(
                                                      `/finance/journals/${line.journal_line?.journal?.id}`,
                                                  ),
                                          },
                                      ]
                                    : []
                            }
                            footerRows={linesFooter}
                        />
                    )}
                </div>
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={amendOpen}
                onClose={() => setAmendOpen(false)}
                title="Prepare a GST amendment?"
                description="This creates a new draft revision from the latest source evidence. The filed return stays unchanged and auditable."
                confirmText="Prepare amendment"
                processing={amending}
                onConfirm={confirmAmendment}
            />

            <ConfirmDialog
                variant="default"
                open={fileOpen}
                onClose={() => setFileOpen(false)}
                title="Mark GST return as filed?"
                description={
                    <>
                        This marks the GST return for IRD period{' '}
                        <span className="font-medium text-foreground">
                            {gstReturn.ird_period}
                        </span>{' '}
                        as filed and locks it. This can&rsquo;t be undone.
                    </>
                }
                confirmText="Mark as filed"
                processing={filing}
                onConfirm={confirmFile}
            />
        </AppLayout>
    );
}
