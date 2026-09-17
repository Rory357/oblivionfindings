import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
} from '@/components/finance';
import {
    EmptyValue,
    EntityChip,
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
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, CheckCircle, RotateCcw, Wallet } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

interface Account {
    id: number;
    code: string;
    name: string;
}

interface CostCentre {
    id: number;
    code: string;
    name: string;
}

interface FundingStream {
    id: number;
    code: string;
    name: string;
}

interface TaxRate {
    id: number;
    code: string;
    name: string;
    rate: string;
}

interface JournalLine {
    id: number;
    account: Account | null;
    description: string | null;
    debit: string;
    credit: string;
    cost_centre: CostCentre | null;
    funding_stream: FundingStream | null;
    tax_rate: TaxRate | null;
    tax_amount: string;
}

interface FiscalPeriod {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
}

interface UserRef {
    id: number;
    name: string;
}

interface ReversedByJournal {
    id: number;
    journal_number: string;
}

interface Journal {
    id: number;
    journal_number: string;
    journal_date: string;
    type: string;
    reference: string | null;
    description: string | null;
    status: string;
    total_amount: string;
    posted_at: string | null;
    fiscal_period: FiscalPeriod | null;
    posted_by: UserRef | null;
    created_by: UserRef | null;
    reversed_by_journal: ReversedByJournal | null;
    lines: JournalLine[];
}

interface Props {
    journal: Journal;
}

const typeLabels: Record<string, string> = {
    standard: 'Standard',
    adjustment: 'Adjustment',
    opening: 'Opening',
};

/** `journal_date` is a date cast — it arrives as an ISO instant. */
const journalDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

export default function JournalsShow({ journal }: Props) {
    const [reverseOpen, setReverseOpen] = useState(false);
    const [reverseReason, setReverseReason] = useState('');
    const [posting, setPosting] = useState(false);
    const [reversing, setReversing] = useState(false);
    const linesRef = useRef<HTMLDivElement>(null);

    const totalDebits = journal.lines.reduce(
        (sum, l) => sum + Number(l.debit),
        0,
    );
    const totalCredits = journal.lines.reduce(
        (sum, l) => sum + Number(l.credit),
        0,
    );
    const balanced = Math.abs(totalDebits - totalCredits) < 0.005;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'General ledger', href: '/finance/ledger' },
        { title: 'Journals', href: '/finance/journals' },
        { title: journal.journal_number },
    ];

    const handlePost = () => {
        setPosting(true);
        router.post(
            `/finance/journals/${journal.id}/post`,
            {},
            { onFinish: () => setPosting(false) },
        );
    };

    const handleReverse = () => {
        setReversing(true);
        router.post(
            `/finance/journals/${journal.id}/reverse`,
            { reason: reverseReason },
            {
                onFinish: () => {
                    setReversing(false);
                    setReverseOpen(false);
                    setReverseReason('');
                },
            },
        );
    };

    const scrollToLines = () =>
        linesRef.current?.scrollIntoView({ block: 'start' });

    /* ---------------- Lines table ---------------- */

    // A posted journal's lines are immutable — the row menu carries the one
    // real action, opening the account the line hit.
    const lineActions = (line: JournalLine): MenuItem[] =>
        line.account
            ? [
                  {
                      label: `Open account ${line.account.code}`,
                      icon: Wallet,
                      onClick: () =>
                          router.visit(`/finance/accounts/${line.account?.id}`),
                  },
              ]
            : [];

    const columns: EntityTableColumn<JournalLine>[] = [
        {
            key: 'description',
            label: 'Description',
            width: '1.3fr',
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
                Number(line.debit) > 0 ? (
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
                Number(line.credit) > 0 ? (
                    <span className="tabular-nums">
                        {formatMoney(line.credit)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'cost_centre',
            label: 'Cost centre',
            width: '180px',
            cell: (line) =>
                line.cost_centre ? (
                    <EntityChip outline>
                        {line.cost_centre.code} — {line.cost_centre.name}
                    </EntityChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'funding_stream',
            label: 'Funding stream',
            width: '190px',
            cell: (line) =>
                line.funding_stream ? (
                    <EntityChip outline>
                        {line.funding_stream.code} — {line.funding_stream.name}
                    </EntityChip>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    const footerRows: EntityTableFooterRow[] = [
        {
            key: 'totals',
            label: 'Totals',
            tone: 'strong',
            cells: {
                debit: (
                    <span className="tabular-nums">
                        {formatMoney(totalDebits)}
                    </span>
                ),
                credit: (
                    <span className="tabular-nums">
                        {formatMoney(totalCredits)}
                    </span>
                ),
            },
        },
    ];

    const meta: { label: string; value: ReactNode }[] = [
        { label: 'Journal date', value: journalDate(journal.journal_date) },
        { label: 'Reference', value: journal.reference || '—' },
        {
            label: 'Fiscal period',
            value: journal.fiscal_period ? journal.fiscal_period.name : '—',
        },
        {
            label: 'Posted by',
            value:
                journal.status === 'posted' && journal.posted_by
                    ? `${journal.posted_by.name}${
                          journal.posted_at
                              ? ` · ${formatDateTime(journal.posted_at)}`
                              : ''
                      }`
                    : '—',
        },
        { label: 'Created by', value: journal.created_by?.name ?? '—' },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            variant="profile"
            icon={BookOpen}
            backHref="/finance/journals"
            title={journal.journal_number}
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        journal.status === 'posted'
                            ? 'success'
                            : journal.status === 'reversed'
                              ? 'critical'
                              : 'neutral'
                    }
                >
                    {journal.status.charAt(0).toUpperCase() +
                        journal.status.slice(1)}
                </PageHeaderStatusChip>
            }
            subline={[
                typeLabels[journal.type] ?? journal.type,
                journalDate(journal.journal_date),
                journal.reference,
                journal.description,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {journal.status === 'posted' &&
                    !journal.reversed_by_journal ? (
                        <PageHeaderGlassButton
                            icon={RotateCcw}
                            onClick={() => setReverseOpen(true)}
                        >
                            Reverse
                        </PageHeaderGlassButton>
                    ) : null}
                    {journal.status === 'draft' ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            disabled={posting}
                            onClick={handlePost}
                        >
                            {posting ? 'Posting…' : 'Post journal'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total debits"
                        ariaLabel="Jump to the journal lines"
                        onClick={scrollToLines}
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalDebits)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {journal.lines.length} lines
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Total credits"
                        ariaLabel="Jump to the journal lines"
                        onClick={scrollToLines}
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalCredits)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {journal.lines.length} lines
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Balance"
                        tone={balanced ? 'success' : 'critical'}
                        ariaLabel="Jump to the journal lines"
                        onClick={scrollToLines}
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(Math.abs(totalDebits - totalCredits))}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {balanced
                                ? 'debits equal credits'
                                : 'out of balance'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    {journal.fiscal_period ? (
                        <PageHeaderMeterBlock
                            label="Fiscal period"
                            href="/finance/fiscal-periods"
                            ariaLabel="View fiscal periods"
                        >
                            <PageHeaderMeterBig>
                                {journal.fiscal_period.name}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {journalDate(journal.fiscal_period.start_date)} –{' '}
                                {journalDate(journal.fiscal_period.end_date)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Journal ${journal.journal_number}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {journal.reversed_by_journal ? (
                        <div className="flex items-center gap-2 rounded-[14px] border border-status-critical/30 bg-status-critical-bg px-4 py-3">
                            <StatusBadge status="reversed" />
                            <p className="text-sm text-status-critical">
                                This journal has been reversed by{' '}
                                <Link
                                    href={`/finance/journals/${journal.reversed_by_journal.id}`}
                                    className="font-semibold underline underline-offset-4"
                                >
                                    {
                                        journal.reversed_by_journal
                                            .journal_number
                                    }
                                </Link>
                            </p>
                        </div>
                    ) : null}

                    <Card className="rounded-[14px] p-5">
                        <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-5">
                            {meta.map((item) => (
                                <div key={item.label}>
                                    <dt className="text-[11.5px] text-muted-foreground">
                                        {item.label}
                                    </dt>
                                    <dd className="text-[13px] font-semibold text-foreground">
                                        {item.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Card>

                    <div
                        ref={linesRef}
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <ListCaption
                            title="Journal lines"
                            caption={`${journal.lines.length} lines · ${formatMoney(journal.total_amount)} total`}
                        />
                        <EntityTable
                            rows={journal.lines}
                            rowKey={(line) => line.id}
                            identityLabel="Account"
                            minWidth={1100}
                            identity={(line) => ({
                                icon: Wallet,
                                name: line.account
                                    ? `${line.account.code} — ${line.account.name}`
                                    : 'Unassigned account',
                            })}
                            hrefFor={(line) =>
                                line.account
                                    ? `/finance/accounts/${line.account.id}`
                                    : `/finance/journals/${journal.id}`
                            }
                            columns={columns}
                            actionsFor={lineActions}
                            footerRows={footerRows}
                            onOpen={(line) => {
                                if (line.account) {
                                    router.visit(
                                        `/finance/accounts/${line.account.id}`,
                                    );
                                }
                            }}
                        />
                    </div>
                </div>
            </PageLayout>

            <ConfirmDialog
                open={reverseOpen}
                onClose={() => setReverseOpen(false)}
                title={`Reverse journal ${journal.journal_number}?`}
                description={
                    <div className="flex flex-col gap-3">
                        <p>
                            This posts a new reversing journal to the ledger
                            that swaps every debit and credit on{' '}
                            <span className="font-medium text-foreground">
                                {journal.journal_number}
                            </span>
                            . The reversing journal posts immediately and
                            can&rsquo;t be undone.
                        </p>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="reverse-reason">
                                Reason (optional)
                            </Label>
                            <Textarea
                                id="reverse-reason"
                                rows={3}
                                value={reverseReason}
                                onChange={(e) =>
                                    setReverseReason(e.target.value)
                                }
                                placeholder="Why is this journal being reversed?"
                            />
                        </div>
                    </div>
                }
                confirmText="Reverse journal"
                variant="destructive"
                processing={reversing}
                onConfirm={handleReverse}
            />
        </AppLayout>
    );
}
