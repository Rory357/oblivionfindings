import { FinanceSectionRail, FinanceTierTwoNav } from '@/components/finance';
import { formatMoney } from '@/components/finance/money';
import {
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarRange, FileText, Printer, Receipt } from 'lucide-react';
import { useState } from 'react';

type ClientOption = {
    id: number;
    name: string;
    email: string | null;
};

type StatementInvoice = {
    invoice_number: string;
    issue_date: string;
    due_date: string;
    total: number;
    amount_paid: number;
    amount_due: number;
};

type Statement = {
    client: {
        id: number;
        name: string;
        email: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        suburb: string | null;
        city: string | null;
        postcode: string | null;
    };
    invoices: StatementInvoice[];
    total_outstanding: number;
    as_of_date: string;
};

type Filters = {
    client_id: number | null;
    as_of_date: string;
};

type PageProps = {
    clients: ClientOption[];
    statement: Statement | null;
    filters: Filters;
};

const NONE = '__none';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Aged AR', href: '/finance/receivables' },
    { title: 'Statements', href: '/finance/receivables/statements' },
];

const formatDate = (date: string) =>
    new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

function ClientAddress({ client }: { client: Statement['client'] }) {
    const parts = [
        client.address_line_1,
        client.address_line_2,
        [client.suburb, client.city, client.postcode].filter(Boolean).join(', '),
    ].filter(Boolean);

    if (parts.length === 0) return null;

    return (
        <div className="text-sm text-muted-foreground">
            {parts.map((part, i) => (
                <div key={i}>{part}</div>
            ))}
        </div>
    );
}

export default function Statements({ clients, statement, filters }: PageProps) {
    const [asOf, setAsOf] = useState(filters.as_of_date);
    const [dateOpen, setDateOpen] = useState(false);

    const go = (next: { client_id?: string | null; as_of_date?: string }) => {
        const params: Record<string, string> = {
            as_of_date: next.as_of_date ?? filters.as_of_date,
        };
        const clientId =
            next.client_id !== undefined
                ? next.client_id
                : filters.client_id
                  ? String(filters.client_id)
                  : null;
        if (clientId && clientId !== NONE) params.client_id = clientId;

        router.get('/finance/receivables/statements', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const overdue = (statement?.invoices ?? []).filter(
        (invoice) => new Date(invoice.due_date) < new Date(filters.as_of_date),
    );
    const overdueTotal = overdue.reduce(
        (total, invoice) => total + invoice.amount_due,
        0,
    );

    const columns: EntityTableColumn<StatementInvoice>[] = [
        {
            key: 'issue_date',
            label: 'Issued',
            width: '0.9fr',
            cell: (invoice) => formatDate(invoice.issue_date),
        },
        {
            key: 'due_date',
            label: 'Due',
            width: '0.9fr',
            cell: (invoice) => (
                <span
                    className={
                        new Date(invoice.due_date) <
                        new Date(filters.as_of_date)
                            ? 'font-semibold text-status-critical'
                            : undefined
                    }
                >
                    {formatDate(invoice.due_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Invoice total',
            width: '0.9fr',
            align: 'right',
            cell: (invoice) => (
                <span className="tabular-nums">
                    {formatMoney(invoice.total)}
                </span>
            ),
        },
        {
            key: 'amount_paid',
            label: 'Received',
            width: '0.9fr',
            align: 'right',
            cell: (invoice) => (
                <span className="tabular-nums">
                    {formatMoney(invoice.amount_paid)}
                </span>
            ),
        },
        {
            key: 'amount_due',
            label: 'Owing',
            width: '0.9fr',
            align: 'right',
            cell: (invoice) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(invoice.amount_due)}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={FileText}
            title="Client statements"
            titleChip={
                statement ? (
                    <PageHeaderStatusChip
                        variant={
                            statement.total_outstanding > 0
                                ? 'warning'
                                : 'success'
                        }
                    >
                        {statement.total_outstanding > 0
                            ? `${formatMoney(statement.total_outstanding)} owing`
                            : 'Settled in full'}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        No payer selected
                    </PageHeaderStatusChip>
                )
            }
            subline={
                statement
                    ? `${statement.client.name} · as at ${formatDate(statement.as_of_date)} · ${statement.invoices.length} open invoices`
                    : `Statements of account · ${clients.length} payers with sent invoices`
            }
            actions={
                statement ? (
                    <PageHeaderGlassButton
                        icon={Printer}
                        onClick={() => window.print()}
                    >
                        Print
                    </PageHeaderGlassButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Statement total"
                        tone={
                            statement && statement.total_outstanding > 0
                                ? 'warning'
                                : 'brand'
                        }
                        href="/finance/receivables"
                        ariaLabel="View aged receivables"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(statement?.total_outstanding ?? 0)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {statement
                                ? `As at ${formatDate(statement.as_of_date)}`
                                : 'Select a payer'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Past due"
                        tone={overdueTotal > 0 ? 'critical' : 'brand'}
                        href="/finance/invoices?status=overdue"
                        ariaLabel="View overdue invoices"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(overdueTotal)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {overdue.length} invoices past due
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open invoices"
                        href="/finance/invoices?status=unpaid"
                        ariaLabel="View unpaid invoices"
                    >
                        <PageHeaderMeterBig>
                            {statement?.invoices.length ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            On this statement
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Payers"
                        href="/finance/receivables"
                        ariaLabel="View every payer with a balance"
                    >
                        <PageHeaderMeterBig>
                            {clients.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            With sent invoices
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Payer"
                        value={
                            filters.client_id ? String(filters.client_id) : NONE
                        }
                        allValue={NONE}
                        options={[
                            { value: NONE, label: 'Select a payer' },
                            ...clients.map((client) => ({
                                value: String(client.id),
                                label: client.name,
                            })),
                        ]}
                        onChange={(value) =>
                            go({ client_id: value === NONE ? null : value })
                        }
                    />
                    <Popover open={dateOpen} onOpenChange={setDateOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active
                                aria-label="Change the statement date"
                            >
                                {`As at ${formatDate(filters.as_of_date)}`}
                            </PageHeaderFilterButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setDateOpen(false);
                                    go({ as_of_date: asOf });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="statement-as-of">
                                        As at
                                    </Label>
                                    <Input
                                        id="statement-as-of"
                                        type="date"
                                        value={asOf}
                                        onChange={(e) =>
                                            setAsOf(e.target.value)
                                        }
                                    />
                                </div>
                                <Button type="submit" size="sm">
                                    Show this date
                                </Button>
                            </form>
                        </PopoverContent>
                    </Popover>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Client statements" />

            <PageLayout hero={header} tabs={<FinanceTierTwoNav />}>
                <div className="flex flex-col gap-5">
                    {!statement ? (
                        <EmptyList
                            icon={FileText}
                            itemName="statement"
                            title="Select a payer"
                            description="Pick a payer in the header to generate their statement of account."
                        />
                    ) : (
                        <>
                            <Card>
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div>
                                            <CardTitle className="text-base">
                                                Statement of account
                                            </CardTitle>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                As at{' '}
                                                {formatDate(
                                                    statement.as_of_date,
                                                )}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-semibold">
                                                {statement.client.name}
                                            </p>
                                            <ClientAddress
                                                client={statement.client}
                                            />
                                            {statement.client.email && (
                                                <p className="text-sm text-muted-foreground">
                                                    {statement.client.email}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="text-sm text-muted-foreground">
                                    Total owing{' '}
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {formatMoney(
                                            statement.total_outstanding,
                                        )}
                                    </span>
                                    {overdueTotal > 0 && (
                                        <>
                                            {' · '}
                                            <span className="font-semibold text-status-critical tabular-nums">
                                                {formatMoney(overdueTotal)}
                                            </span>{' '}
                                            past due
                                        </>
                                    )}
                                </CardContent>
                            </Card>

                            <ListCaption
                                title="Open invoices"
                                caption={`${statement.invoices.length} on this statement`}
                            />

                            {statement.invoices.length === 0 ? (
                                <EmptyList
                                    icon={Receipt}
                                    itemName="invoice"
                                    title="Nothing outstanding"
                                    description={`No invoices were outstanding as at ${formatDate(statement.as_of_date)}.`}
                                />
                            ) : (
                                <EntityTable
                                    rows={statement.invoices}
                                    rowKey={(invoice) => invoice.invoice_number}
                                    identityLabel="Invoice"
                                    identity={(invoice) => ({
                                        icon: Receipt,
                                        name: invoice.invoice_number,
                                        subline: statement.client.name,
                                    })}
                                    columns={columns}
                                    actionsFor={() => []}
                                    minWidth={960}
                                />
                            )}
                        </>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
