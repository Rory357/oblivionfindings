import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportCard,
} from '@/components/finance/report-page';
import {
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
} from '@/components/page';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Printer, type LucideIcon } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * The one ageing report body. `/finance/reports/aged-payables` and
 * `/finance/reports/aged-receivables` are structurally identical — same
 * buckets, same grand-total row, same charts — so they render this
 * component with their own entity label, and keep their own page files
 * (and routes) as thin wrappers.
 *
 * No default export: this is a body, not a routed Inertia page.
 */

export type AgedBucketKey =
    | 'current'
    | 'days_1_30'
    | 'days_31_60'
    | 'days_61_90'
    | 'days_90_plus';

export type AgedBuckets = {
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    days_90_plus: number;
    total: number;
};

export type AgedReportRow = AgedBuckets & { name: string };

const BUCKETS: { key: AgedBucketKey; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: 'days_1_30', label: '1–30 days' },
    { key: 'days_31_60', label: '31–60 days' },
    { key: 'days_61_90', label: '61–90 days' },
    { key: 'days_90_plus', label: '90+ days' },
];

const ALL = 'all';

const truncate = (value: string, max: number) =>
    value.length > max ? `${value.substring(0, max)}…` : value;

export function AgedReport({
    icon,
    title,
    entityLabel,
    entityPlural,
    rows,
    grandTotal,
    ledgerHref,
    ledgerLabel,
}: {
    icon: LucideIcon;
    title: string;
    /** "Vendor" / "Client" — the identity column and copy. */
    entityLabel: string;
    entityPlural: string;
    rows: AgedReportRow[];
    grandTotal: AgedBuckets;
    /** Where the underlying documents live (bills / invoices). */
    ledgerHref: string;
    ledgerLabel: string;
}) {
    const [search, setSearch] = useState('');
    const [bucket, setBucket] = useState<string>(ALL);

    const overdueAmount =
        grandTotal.days_31_60 +
        grandTotal.days_61_90 +
        grandTotal.days_90_plus;

    const currentPct =
        grandTotal.total > 0
            ? (grandTotal.current / grandTotal.total) * 100
            : 0;

    const overduePct =
        grandTotal.total > 0 ? (overdueAmount / grandTotal.total) * 100 : 0;

    const severelyOverdue = rows.filter((r) => r.days_90_plus > 0).length;

    const pieData = useMemo(
        () =>
            BUCKETS.map((b) => ({
                name: b.label,
                value: grandTotal[b.key],
            })).filter((d) => d.value > 0),
        [grandTotal],
    );

    const barData = useMemo(
        () =>
            [...rows]
                .sort((a, b) => b.total - a.total)
                .slice(0, 10)
                .map((r) => ({
                    name: truncate(r.name, 20),
                    total: r.total,
                })),
        [rows],
    );

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();
        return rows.filter((row) => {
            if (term && !row.name.toLowerCase().includes(term)) return false;
            if (bucket !== ALL && row[bucket as AgedBucketKey] <= 0)
                return false;
            return true;
        });
    }, [rows, search, bucket]);

    const columns: EntityTableColumn<AgedReportRow>[] = [
        ...BUCKETS.map((b) => ({
            key: b.key,
            label: b.label,
            width: '1fr',
            align: 'right' as const,
            cell: (row: AgedReportRow) => (
                <span className="tabular-nums">
                    {row[b.key] > 0 ? formatMoney(row[b.key]) : '—'}
                </span>
            ),
        })),
        {
            key: 'total',
            label: 'Total',
            width: '1.1fr',
            align: 'right',
            cell: (row) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(row.total)}
                </span>
            ),
        },
    ];

    const grandTotalCells: Record<string, ReactNode> = {
        total: (
            <span className="tabular-nums">
                {formatMoney(grandTotal.total)}
            </span>
        ),
    };
    for (const b of BUCKETS) {
        grandTotalCells[b.key] = (
            <span className="tabular-nums">{formatMoney(grandTotal[b.key])}</span>
        );
    }

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Outstanding"
                href={ledgerHref}
                ariaLabel={`View ${ledgerLabel}`}
            >
                <PageHeaderMeterBig>
                    {formatMoney(grandTotal.total)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {rows.length} {entityPlural.toLowerCase()} with a balance
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Not yet due"
                tone={currentPct >= 75 ? 'success' : 'brand'}
                href={ledgerHref}
                ariaLabel={`View ${ledgerLabel}`}
            >
                <PageHeaderMeterDonut
                    percent={currentPct}
                    caption={
                        grandTotal.total > 0
                            ? `${formatMoney(grandTotal.current)} of ${formatMoney(grandTotal.total)}`
                            : 'Nothing outstanding'
                    }
                />
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Overdue 31+ days"
                tone={overdueAmount > 0 ? 'warning' : 'success'}
                href={ledgerHref}
                ariaLabel={`View ${ledgerLabel}`}
            >
                <PageHeaderMeterBig>
                    {formatMoney(overdueAmount)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {overduePct.toFixed(1)}% of the balance
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="90+ days"
                tone={grandTotal.days_90_plus > 0 ? 'critical' : 'success'}
                href={ledgerHref}
                ariaLabel={`View ${ledgerLabel}`}
            >
                <PageHeaderMeterBig>
                    {formatMoney(grandTotal.days_90_plus)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {severelyOverdue} {entityPlural.toLowerCase()} affected
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={icon}
            title={title}
            periodLabel="As at today"
            subline={`Outstanding balances by ${entityLabel.toLowerCase()} and ageing bucket`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder={`Search ${entityPlural.toLowerCase()}…`}
                    />
                    <PageHeaderGlassButton
                        icon={Printer}
                        onClick={() => window.print()}
                    >
                        Print
                    </PageHeaderGlassButton>
                </>
            }
            meters={meters}
            filters={
                <PageHeaderFilterSelect
                    label="Ageing"
                    value={bucket}
                    allValue={ALL}
                    options={[
                        { value: ALL, label: 'Every bucket' },
                        ...BUCKETS.map((b) => ({
                            value: b.key,
                            label: b.label,
                        })),
                    ]}
                    onChange={setBucket}
                />
            }
        >
            {rows.length > 0 && (
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <ReportCard title="Ageing buckets" scroll={false}>
                        <div className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={pieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={50}
                                        outerRadius={90}
                                        paddingAngle={2}
                                        dataKey="value"
                                        label={({ name, percent }) =>
                                            `${name} ${((percent ?? 0) * 100).toFixed(0)}%`
                                        }
                                    >
                                        {pieData.map((_, idx) => (
                                            <Cell
                                                key={idx}
                                                fill={chartColor(idx)}
                                            />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        formatter={(value) =>
                                            formatMoney(value as number)
                                        }
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                    </ReportCard>

                    <ReportCard
                        title={`Largest 10 ${entityPlural.toLowerCase()}`}
                        scroll={false}
                    >
                        <div className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={barData}
                                    layout="vertical"
                                    margin={{ left: 20, right: 20 }}
                                >
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis
                                        type="number"
                                        tickFormatter={(v) => formatMoney(v)}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        width={130}
                                        tick={{ fontSize: 12 }}
                                    />
                                    <Tooltip
                                        formatter={(value) =>
                                            formatMoney(value as number)
                                        }
                                    />
                                    <Bar
                                        dataKey="total"
                                        fill={chartColor(0)}
                                        radius={[0, 4, 4, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ReportCard>
                </div>
            )}

            <ListCaption
                title={`Outstanding by ${entityLabel.toLowerCase()}`}
                caption={`${visible.length} of ${rows.length} shown`}
            />

            {rows.length === 0 ? (
                <EmptyList
                    icon={icon}
                    itemName="balance"
                    title="Nothing outstanding"
                    description={`No ${entityPlural.toLowerCase()} have an outstanding balance today.`}
                />
            ) : visible.length === 0 ? (
                <EmptySearch
                    searchTerm={search}
                    onClear={() => {
                        setSearch('');
                        setBucket(ALL);
                    }}
                />
            ) : (
                <EntityTable
                    rows={visible}
                    rowKey={(row) => row.name}
                    identityLabel={entityLabel}
                    identity={(row) => ({
                        icon,
                        name: row.name,
                        subline:
                            row.days_90_plus > 0
                                ? `${formatMoney(row.days_90_plus)} over 90 days`
                                : undefined,
                    })}
                    columns={columns}
                    actionsFor={() => []}
                    minWidth={1080}
                    footerRows={[
                        {
                            key: 'grand-total',
                            label: 'Grand total',
                            tone: 'strong',
                            cells: grandTotalCells,
                        },
                    ]}
                />
            )}
        </FinanceReportPage>
    );
}
