import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
    QuoteDialog,
    type QuoteClientOption,
    type QuotePriceBook,
} from '@/components/finance';
import { EntityTable, ListCaption } from '@/components/lists';
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
import { Separator } from '@/components/ui/separator';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    Calculator,
    CheckCircle2,
    FileText,
    Pencil,
    Receipt,
    Send,
} from 'lucide-react';
import { useState } from 'react';

type LineItem = {
    id: number;
    description: string;
    quantity: number | string;
    unit: string | null;
    unit_price: number | string;
    amount: number | string;
};

type Quote = {
    id: number;
    quote_number: string;
    title: string;
    status: string;
    client_id: number | null;
    client_name: string | null;
    client_email: string | null;
    client_phone: string | null;
    valid_until: string | null;
    notes: string | null;
    terms: string | null;
    subtotal: number | string;
    tax_amount: number | string;
    total_amount: number | string;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
    line_items: LineItem[];
};

type Props = {
    quote: Quote;
    canManage: boolean;
    clients: QuoteClientOption[];
    priceBooks: QuotePriceBook[];
};

type PendingAction = 'send' | 'accept' | 'convert' | 'convert-to-invoice';

const ACTION_COPY: Record<
    PendingAction,
    { title: string; description: string; confirmText: string }
> = {
    send: {
        title: 'Send this quote?',
        description:
            'This moves the quote to Sent and records the send date. The line items are locked from then on.',
        confirmText: 'Send quote',
    },
    accept: {
        title: 'Mark this quote as accepted?',
        description:
            'This records the client’s acceptance and unlocks conversion to a service agreement or an invoice.',
        confirmText: 'Mark accepted',
    },
    convert: {
        title: 'Convert to a service agreement?',
        description:
            'This creates a draft service agreement from the quote’s lines and closes the quote as converted. It can’t be undone.',
        confirmText: 'Convert to agreement',
    },
    'convert-to-invoice': {
        title: 'Convert to an AR invoice?',
        description:
            'This creates a draft invoice from the quote’s lines, applies NZ GST and closes the quote as converted. It can’t be undone.',
        confirmText: 'Convert to invoice',
    },
};

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function QuoteShow({
    quote,
    canManage = false,
    clients = [],
    priceBooks = [],
}: Props) {
    const [pending, setPending] = useState<PendingAction | null>(null);
    const [processing, setProcessing] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const clientDisplay = quote.client
        ? `${quote.client.first_name} ${quote.client.last_name}`
        : (quote.client_name ?? 'Unknown');

    const expired =
        quote.valid_until &&
        new Date(quote.valid_until) < new Date() &&
        !['accepted', 'converted'].includes(quote.status);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Receivables', href: '/finance/invoices' },
        { title: 'Quotes', href: '/finance/quotes' },
        { title: quote.quote_number },
    ];

    const runAction = (action: PendingAction) => {
        router.post(
            `/finance/quotes/${quote.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setPending(null);
                },
            },
        );
    };

    const header = (
        <PageHeader
            variant="profile"
            icon={Calculator}
            backHref="/finance/quotes"
            title={quote.quote_number}
            titleChip={
                expired ? (
                    <PageHeaderStatusChip variant="critical">
                        Expired
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip
                        variant={
                            ['accepted', 'converted'].includes(quote.status)
                                ? 'success'
                                : quote.status === 'declined'
                                  ? 'critical'
                                  : quote.status === 'draft'
                                    ? 'neutral'
                                    : 'info'
                        }
                    >
                        {quote.status.charAt(0).toUpperCase() +
                            quote.status.slice(1)}
                    </PageHeaderStatusChip>
                )
            }
            subline={`${quote.title} · ${clientDisplay} · raised ${formatDate(quote.created_at)}`}
            actions={
                <>
                    {canManage && quote.status === 'draft' && (
                        <>
                            <PageHeaderGlassButton
                                icon={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Send}
                                onClick={() => setPending('send')}
                            >
                                Send quote
                            </PageHeaderPrimaryButton>
                        </>
                    )}
                    {canManage && quote.status === 'sent' && (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            onClick={() => setPending('accept')}
                        >
                            Mark accepted
                        </PageHeaderPrimaryButton>
                    )}
                    {canManage && quote.status === 'accepted' && (
                        <>
                            <PageHeaderGlassButton
                                icon={FileText}
                                onClick={() => setPending('convert')}
                            >
                                Convert to agreement
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Receipt}
                                onClick={() =>
                                    setPending('convert-to-invoice')
                                }
                            >
                                Convert to invoice
                            </PageHeaderPrimaryButton>
                        </>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Quote total"
                        href="/finance/quotes"
                        ariaLabel="Back to the quote register"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(quote.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Incl. {formatMoney(quote.tax_amount)} GST
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Lines"
                        href="/finance/price-books"
                        ariaLabel="View the price books these rates come from"
                    >
                        <PageHeaderMeterBig>
                            {quote.line_items.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Priced service lines
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Valid until"
                        tone={expired ? 'critical' : 'brand'}
                        href="/finance/quotes?status=sent"
                        ariaLabel="View open quotes"
                    >
                        <PageHeaderMeterBig>
                            {formatDate(quote.valid_until)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {expired
                                ? 'Past its valid-until date'
                                : 'Offer open until this date'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Client"
                        href={`/finance/invoices?search=${encodeURIComponent(
                            clientDisplay,
                        )}`}
                        ariaLabel="View this client's invoices"
                    >
                        <PageHeaderMeterBig>
                            {clientDisplay}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {quote.client_email ?? 'No email on file'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={quote.quote_number} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Quote details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <StatusBadge status={quote.status} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Title
                                    </span>
                                    <span className="font-medium">
                                        {quote.title}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Raised
                                    </span>
                                    <span className="font-medium">
                                        {formatDate(quote.created_at)}
                                    </span>
                                </div>
                                {quote.creator && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Raised by
                                        </span>
                                        <span className="font-medium">
                                            {quote.creator.name}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Client
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="text-base font-medium">
                                    {clientDisplay}
                                </div>
                                {quote.client_email && (
                                    <div>{quote.client_email}</div>
                                )}
                                {quote.client_phone && (
                                    <div className="text-muted-foreground">
                                        {quote.client_phone}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Totals
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Subtotal
                                    </span>
                                    <span className="tabular-nums">
                                        {formatMoney(quote.subtotal)}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        GST (15%)
                                    </span>
                                    <span className="tabular-nums">
                                        {formatMoney(quote.tax_amount)}
                                    </span>
                                </div>
                                <Separator />
                                <div className="flex justify-between text-base font-bold">
                                    <span>Total (NZD)</span>
                                    <span className="tabular-nums">
                                        {formatMoney(quote.total_amount)}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <ListCaption
                        title="Line items"
                        caption={`${quote.line_items.length} ${
                            quote.line_items.length === 1 ? 'line' : 'lines'
                        }`}
                    />
                    <EntityTable
                        rows={quote.line_items}
                        rowKey={(line) => line.id}
                        identityLabel="Description"
                        identity={(line) => ({
                            icon: FileText,
                            name: line.description,
                            subline: line.unit
                                ? `Priced per ${line.unit}`
                                : undefined,
                        })}
                        actionsFor={() => []}
                        minWidth={820}
                        columns={[
                            {
                                key: 'quantity',
                                label: 'Qty',
                                width: '0.5fr',
                                align: 'right',
                                cell: (line) => Number(line.quantity).toFixed(2),
                            },
                            {
                                key: 'unit_price',
                                label: 'Unit price',
                                width: '0.8fr',
                                align: 'right',
                                cell: (line) => formatMoney(line.unit_price),
                            },
                            {
                                key: 'amount',
                                label: 'Amount',
                                width: '0.8fr',
                                align: 'right',
                                cell: (line) => (
                                    <span className="font-medium tabular-nums">
                                        {formatMoney(line.amount)}
                                    </span>
                                ),
                            },
                        ]}
                    />

                    {(quote.notes || quote.terms) && (
                        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            {quote.notes && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Notes
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                            {quote.notes}
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                            {quote.terms && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Terms and conditions
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                            {quote.terms}
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    )}
                </div>
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={pending !== null}
                onClose={() => setPending(null)}
                title={pending ? ACTION_COPY[pending].title : ''}
                description={pending ? ACTION_COPY[pending].description : ''}
                confirmText={pending ? ACTION_COPY[pending].confirmText : ''}
                processing={processing}
                onConfirm={() => pending && runAction(pending)}
            />

            {canManage && quote.status === 'draft' && editOpen && (
                <QuoteDialog
                    open
                    onClose={() => setEditOpen(false)}
                    clients={clients}
                    priceBooks={priceBooks}
                    quote={{
                        id: quote.id,
                        client_id: quote.client_id,
                        title: quote.title,
                        valid_until: quote.valid_until,
                        notes: quote.notes,
                        lines: quote.line_items.map((line) => ({
                            description: line.description,
                            quantity: line.quantity,
                            unit_price: line.unit_price,
                        })),
                    }}
                />
            )}
        </AppLayout>
    );
}
