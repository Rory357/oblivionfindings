import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
import { formatMoney } from '@/components/finance/money';
import {
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle, FileMinus, FileText } from 'lucide-react';
import { useState } from 'react';

interface CreditNoteLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    gst_amount: string;
    line_total: string;
    account: { id: number; code: string; name: string } | null;
}

interface CreditNote {
    id: number;
    credit_note_number: string;
    type: string;
    vendor: { id: number; name: string } | null;
    status: string;
    credit_date: string;
    subtotal: string;
    gst_amount: string;
    total_amount: string;
    reason: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    journal: {
        id: number;
        journal_number: string;
        status: string;
        posted_at: string;
    } | null;
    lines: CreditNoteLine[];
}

interface Props extends PageProps {
    creditNote: CreditNote;
    canApprove: boolean;
}

const TYPE_LABELS: Record<string, string> = {
    payable: 'Accounts payable',
    receivable: 'Accounts receivable',
};

const CHIP_VARIANTS: Record<string, StatusVariant> = {
    draft: 'neutral',
    approved: 'success',
    applied: 'success',
    cancelled: 'neutral',
};

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const formatDateTime = (date: string | null) =>
    date
        ? new Date(date).toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-caption">{label}</dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}

export default function CreditNoteShow({ creditNote, canApprove }: Props) {
    const isDraft = creditNote.status === 'draft';
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const approve = () =>
        router.post(
            `/finance/credit-notes/${creditNote.id}/approve`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmOpen(false),
            },
        );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Credit notes', href: '/finance/credit-notes' },
        {
            title: creditNote.credit_note_number,
            href: `/finance/credit-notes/${creditNote.id}`,
        },
    ];

    const lineColumns: EntityTableColumn<CreditNoteLine>[] = [
        {
            key: 'qty',
            label: 'Qty',
            width: '70px',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums">
                    {Number(l.quantity).toFixed(2)}
                </span>
            ),
        },
        {
            key: 'unit',
            label: 'Unit price',
            width: '1fr',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums">
                    {formatMoney(l.unit_price)}
                </span>
            ),
        },
        {
            key: 'account',
            label: 'Account',
            width: '1.8fr',
            cell: (l) => (
                <span className="truncate text-muted-foreground">
                    {l.account
                        ? `${l.account.code} · ${l.account.name}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'gst',
            label: 'GST',
            width: '1fr',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums text-muted-foreground">
                    {formatMoney(l.gst_amount)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Line total',
            width: '1.1fr',
            align: 'right',
            cell: (l) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(l.line_total)}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/credit-notes"
            icon={FileMinus}
            title={creditNote.credit_note_number}
            titleChip={
                <PageHeaderStatusChip
                    variant={CHIP_VARIANTS[creditNote.status] ?? 'neutral'}
                >
                    {creditNote.status === 'draft'
                        ? 'Draft'
                        : creditNote.status === 'approved'
                          ? 'Approved'
                          : creditNote.status === 'applied'
                            ? 'Applied'
                            : 'Cancelled'}
                </PageHeaderStatusChip>
            }
            subline={[
                TYPE_LABELS[creditNote.type] ?? creditNote.type,
                creditNote.vendor?.name ?? 'No party recorded',
                formatDate(creditNote.credit_date),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                canApprove && isDraft ? (
                    <PageHeaderPrimaryButton
                        icon={CheckCircle}
                        onClick={() => setConfirmOpen(true)}
                    >
                        Approve
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total credit"
                        href={`/finance/credit-notes/${creditNote.id}`}
                        ariaLabel="View this credit note's total"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(creditNote.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(creditNote.subtotal)} plus GST
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="GST"
                        href={`/finance/credit-notes/${creditNote.id}`}
                        ariaLabel="View this credit note's GST"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(creditNote.gst_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {creditNote.lines.length} line
                            {creditNote.lines.length === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Type"
                        href={`/finance/credit-notes?type=${creditNote.type}`}
                        ariaLabel="View credit notes of this type"
                    >
                        <PageHeaderMeterBig>
                            {creditNote.type === 'payable' ? 'AP' : 'AR'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {TYPE_LABELS[creditNote.type] ?? creditNote.type}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Ledger journal"
                        tone={creditNote.journal ? 'success' : 'warning'}
                        href={
                            creditNote.journal
                                ? `/finance/journals/${creditNote.journal.id}`
                                : '/finance/journals'
                        }
                        ariaLabel="View the ledger journal for this credit note"
                    >
                        <PageHeaderMeterBig>
                            {creditNote.journal
                                ? creditNote.journal.journal_number
                                : 'Not posted'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {creditNote.journal
                                ? `Posted ${formatDate(creditNote.journal.posted_at)}`
                                : 'Approving posts a journal'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Credit note ${creditNote.credit_note_number}`} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Credit note details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <DetailRow label="Credit date">
                                    {formatDate(creditNote.credit_date)}
                                </DetailRow>
                                <DetailRow label="Type">
                                    {TYPE_LABELS[creditNote.type] ??
                                        creditNote.type}
                                </DetailRow>
                                <DetailRow label="Vendor / client">
                                    {creditNote.vendor ? (
                                        <Link
                                            href={`/finance/vendors/${creditNote.vendor.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {creditNote.vendor.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                {creditNote.approved_by && (
                                    <DetailRow label="Approved by">
                                        {creditNote.approved_by.name}
                                        {creditNote.approved_at
                                            ? ` · ${formatDateTime(creditNote.approved_at)}`
                                            : ''}
                                    </DetailRow>
                                )}
                                {creditNote.reason && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-caption">Reason</dt>
                                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                                            {creditNote.reason}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Ledger journal
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {creditNote.journal ? (
                                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <DetailRow label="Journal">
                                        <Link
                                            href={`/finance/journals/${creditNote.journal.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {
                                                creditNote.journal
                                                    .journal_number
                                            }
                                        </Link>
                                    </DetailRow>
                                    <DetailRow label="Status">
                                        <StatusBadge
                                            status={creditNote.journal.status}
                                            className="rounded-[8px] font-semibold"
                                        />
                                    </DetailRow>
                                    <DetailRow label="Posted">
                                        {formatDateTime(
                                            creditNote.journal.posted_at,
                                        )}
                                    </DetailRow>
                                </dl>
                            ) : (
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <FileText className="size-4" />
                                    No journal posted yet — approving this
                                    credit note posts one to the ledger.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <ListCaption
                    title="Line items"
                    caption={`${creditNote.lines.length} line${creditNote.lines.length === 1 ? '' : 's'} · ${formatMoney(creditNote.total_amount)} total`}
                />
                <EntityTable
                    rows={creditNote.lines}
                    rowKey={(l) => l.id}
                    identityLabel="Description"
                    identity={(l) => ({ icon: FileText, name: l.description })}
                    columns={lineColumns}
                    actionsFor={() => []}
                    minWidth={960}
                />
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                title="Approve this credit note?"
                description={
                    <>
                        This approves{' '}
                        <span className="font-medium text-foreground">
                            {creditNote.credit_note_number}
                        </span>{' '}
                        for {formatMoney(creditNote.total_amount)} and{' '}
                        <span className="font-medium text-foreground">
                            posts a journal to the ledger
                        </span>
                        . A credit note can&rsquo;t be edited once approved.
                    </>
                }
                confirmText="Approve credit note"
                processing={processing}
                onConfirm={approve}
            />
        </AppLayout>
    );
}
