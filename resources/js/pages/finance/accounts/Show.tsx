import {
    FinanceSectionRail,
    NewAccountDialog,
    formatMoney,
    type EditableAccount,
} from '@/components/finance';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import {
    EmptyValue,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    type EntityTableFooterRow,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { BookOpen, Pencil, Wallet } from 'lucide-react';
import { useState } from 'react';

type LedgerLine = {
    id: number;
    date: string;
    journal_number: string;
    journal_id: number;
    description: string;
    debit: number;
    credit: number;
    running_balance: number;
};

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    sub_type: string | null;
    is_system: boolean;
    is_active: boolean;
    gst_applicable: boolean;
    description: string | null;
    parent_id: number | null;
    default_tax_rate_id: number | null;
    funding_stream_id: number | null;
    balance: number;
};

type Ledger = {
    opening_balance: number;
    lines: LedgerLine[];
    closing_balance: number;
};

type RefItem = { id: number; code: string; name: string; type?: string };

type PageProps = {
    account: Account;
    ledger: Ledger;
    filters: { from: string; to: string };
    canManage?: boolean;
    parentAccounts?: RefItem[];
    taxRates?: { id: number; name: string; code: string; rate: string }[];
    fundingStreams?: RefItem[];
};

const typeLabels: Record<string, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expense',
};

const dateLabel = (value: string) => {
    const parsed = new Date(`${value}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return value;

    return parsed.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

export default function AccountShow({
    account,
    ledger,
    filters,
    canManage = false,
    parentAccounts = [],
    taxRates = [],
    fundingStreams = [],
}: PageProps) {
    const [editOpen, setEditOpen] = useState(false);
    const ctx = useEntityContextMenu<LedgerLine>();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'General ledger', href: '/finance/ledger' },
        { title: 'Chart of accounts', href: '/finance/accounts' },
        { title: `${account.code} — ${account.name}` },
    ];

    const editable: EditableAccount = {
        id: account.id,
        code: account.code,
        name: account.name,
        type: account.type,
        sub_type: account.sub_type,
        parent_id: account.parent_id,
        description: account.description,
        gst_applicable: account.gst_applicable,
        is_active: account.is_active,
        default_tax_rate_id: account.default_tax_rate_id,
        funding_stream_id: account.funding_stream_id,
    };

    const movement = ledger.closing_balance - ledger.opening_balance;

    const actionsFor = (line: LedgerLine): MenuItem[] => [
        {
            label: `Open journal ${line.journal_number}`,
            icon: BookOpen,
            onClick: () => router.visit(`/finance/journals/${line.journal_id}`),
        },
    ];

    const columns: EntityTableColumn<LedgerLine>[] = [
        {
            key: 'date',
            label: 'Date',
            width: '140px',
            cell: (line) => (
                <span className="whitespace-nowrap text-muted-foreground">
                    {dateLabel(line.date)}
                </span>
            ),
        },
        {
            key: 'description',
            label: 'Description',
            width: '1.4fr',
            cell: (line) =>
                line.description ? (
                    <span className="truncate">{line.description}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'debit',
            label: 'Debit',
            width: '140px',
            align: 'right',
            cell: (line) =>
                line.debit > 0 ? (
                    <span className="tabular-nums">
                        {formatMoney(line.debit)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'credit',
            label: 'Credit',
            width: '140px',
            align: 'right',
            cell: (line) =>
                line.credit > 0 ? (
                    <span className="tabular-nums">
                        {formatMoney(line.credit)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'balance',
            label: 'Balance',
            width: '150px',
            align: 'right',
            cell: (line) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(line.running_balance)}
                </span>
            ),
        },
    ];

    // Opening and closing belong to the balance column, not to synthetic body
    // rows that sort and hover like real journal lines.
    const footerRows: EntityTableFooterRow[] = [
        {
            key: 'opening',
            label: 'Opening balance',
            cells: {
                balance: (
                    <span className="tabular-nums">
                        {formatMoney(ledger.opening_balance)}
                    </span>
                ),
            },
        },
        {
            key: 'closing',
            label: 'Closing balance',
            tone: 'strong',
            cells: {
                balance: (
                    <span className="tabular-nums">
                        {formatMoney(ledger.closing_balance)}
                    </span>
                ),
            },
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={Wallet}
            backHref="/finance/accounts"
            title={`${account.code} — ${account.name}`}
            titleChip={
                <PageHeaderStatusChip
                    variant={account.is_active ? 'success' : 'neutral'}
                >
                    {account.is_active ? 'Active' : 'Inactive'}
                </PageHeaderStatusChip>
            }
            subline={[
                typeLabels[account.type] ?? account.type,
                account.sub_type ? account.sub_type.replace(/_/g, ' ') : null,
                account.is_system ? 'System account' : null,
                account.gst_applicable ? 'GST applicable' : null,
                account.description,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                canManage ? (
                    <PageHeaderGlassButton
                        icon={Pencil}
                        onClick={() => setEditOpen(true)}
                    >
                        Edit account
                    </PageHeaderGlassButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Current balance"
                        href={`/finance/accounts/${account.id}`}
                        ariaLabel="Reload this account's ledger"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(account.balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            all posted journals to date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Opening"
                        href={`/finance/accounts/${account.id}`}
                        ariaLabel="View the opening balance for this period"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(ledger.opening_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            at {dateLabel(filters.from)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Closing"
                        href={`/finance/accounts/${account.id}`}
                        ariaLabel="View the closing balance for this period"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(ledger.closing_balance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            at {dateLabel(filters.to)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Movement"
                        tone={movement >= 0 ? 'success' : 'warning'}
                        href="/finance/journals"
                        ariaLabel="View the journals behind this movement"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(movement)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterDelta
                            trend={movement >= 0 ? 'up' : 'down'}
                            good={movement >= 0}
                        >
                            {ledger.lines.length} journal{' '}
                            {ledger.lines.length === 1 ? 'line' : 'lines'}
                        </PageHeaderMeterDelta>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <FinancePeriodFilter
                    url={`/finance/accounts/${account.id}`}
                    from={filters.from}
                    to={filters.to}
                    idPrefix={`account-${account.id}-period`}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${account.code} — ${account.name}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Account ledger"
                        caption={`${ledger.lines.length} ${
                            ledger.lines.length === 1 ? 'line' : 'lines'
                        } · ${dateLabel(filters.from)} – ${dateLabel(filters.to)}`}
                    />

                    {ledger.lines.length === 0 ? (
                        <EmptyState
                            icon={BookOpen}
                            heading="No journal entries in this period"
                            description="Widen the reporting period to see earlier postings against this account."
                        />
                    ) : (
                        <EntityTable
                            rows={ledger.lines}
                            rowKey={(line) => line.id}
                            identityLabel="Journal"
                            minWidth={980}
                            identity={(line) => ({
                                icon: BookOpen,
                                name: line.journal_number,
                                subline: dateLabel(line.date),
                            })}
                            hrefFor={(line) =>
                                `/finance/journals/${line.journal_id}`
                            }
                            columns={columns}
                            actionsFor={actionsFor}
                            footerRows={footerRows}
                            onOpen={(line) =>
                                router.visit(
                                    `/finance/journals/${line.journal_id}`,
                                )
                            }
                            onRowContextMenu={(e, line) => ctx.open(e, line)}
                        />
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={BookOpen}
                    title={ctx.ctx.record.journal_number}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage ? (
                <NewAccountDialog
                    open={editOpen}
                    account={editable}
                    onClose={() => setEditOpen(false)}
                    parentAccounts={parentAccounts}
                    taxRates={taxRates}
                    fundingStreams={fundingStreams}
                />
            ) : null}
        </AppLayout>
    );
}
