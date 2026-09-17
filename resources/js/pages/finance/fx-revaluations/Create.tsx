import { FinanceSectionRail, formatMoney } from '@/components/finance';
import {
    EntityChip,
    EntityTable,
    type EntityTableColumn,
    type EntityTableFooterRow,
    ListCaption,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, CalendarRange, Globe } from 'lucide-react';
import { useRef, useState } from 'react';

type PreviewItem = {
    type: string;
    reference: string;
    currency_code: string;
    foreign_amount: number;
    booked_rate: number;
    current_rate: number;
    booked_base_value: number;
    current_base_value: number;
    gain_loss: number;
};

type Preview = {
    items: PreviewItem[];
    total_gain_loss: number;
};

type PageProps = {
    preview: Preview;
    date: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'FX revaluations', href: '/finance/fx-revaluations' },
    { title: 'New revaluation' },
];

const typeLabels: Record<string, string> = {
    bill: 'Bill',
    bank_account: 'Bank account',
};

const formatRate = (rate: number) => rate.toFixed(6);

const revalDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

/** Losses read as "(1,234.00)" — the accounting convention. */
const signedMoney = (value: number) =>
    value < 0
        ? `(${formatMoney(Math.abs(value))})`
        : formatMoney(Math.abs(value));

/**
 * New FX revaluation — a routed page, not a modal (decision D8): the preview is
 * a live server-side calculation against every open foreign-currency item at
 * the chosen date, re-queried each time the date changes.
 */
export default function FxRevaluationCreate({ preview, date }: PageProps) {
    const [datePickerOpen, setDatePickerOpen] = useState(false);
    const [pendingDate, setPendingDate] = useState(date);
    const previewRef = useRef<HTMLDivElement>(null);

    const { data, setData, post, processing, errors } = useForm({
        date,
        notes: '',
    });

    const applyDate = (newDate: string) => {
        setDatePickerOpen(false);
        setData('date', newDate);
        router.get(
            '/finance/fx-revaluations/create',
            { date: newDate },
            { preserveState: true },
        );
    };

    const handleSubmit = (event?: { preventDefault?: () => void }) => {
        event?.preventDefault?.();
        post('/finance/fx-revaluations');
    };

    const totalGainLoss = preview.total_gain_loss;
    const isGain = totalGainLoss >= 0;
    const hasItems = preview.items.length > 0;
    const currencies = new Set(preview.items.map((i) => i.currency_code)).size;

    const scrollToPreview = () =>
        previewRef.current?.scrollIntoView({ block: 'start' });

    // The preview is a calculation, not a register — its rows carry no actions.
    const noActions = (): MenuItem[] => [];

    const columns: EntityTableColumn<PreviewItem>[] = [
        {
            key: 'currency',
            label: 'Currency',
            width: '120px',
            cell: (item) => <EntityChip>{item.currency_code}</EntityChip>,
        },
        {
            key: 'foreign',
            label: 'Foreign amount',
            width: '150px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">
                    {item.foreign_amount.toFixed(2)}
                </span>
            ),
        },
        {
            key: 'booked_rate',
            label: 'Booked rate',
            width: '130px',
            align: 'right',
            cell: (item) => (
                <span className="text-muted-foreground tabular-nums">
                    {formatRate(item.booked_rate)}
                </span>
            ),
        },
        {
            key: 'current_rate',
            label: 'Current rate',
            width: '130px',
            align: 'right',
            cell: (item) => (
                <span className="text-muted-foreground tabular-nums">
                    {formatRate(item.current_rate)}
                </span>
            ),
        },
        {
            key: 'booked_nzd',
            label: 'Booked NZD',
            width: '140px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">
                    {formatMoney(item.booked_base_value)}
                </span>
            ),
        },
        {
            key: 'current_nzd',
            label: 'Current NZD',
            width: '140px',
            align: 'right',
            cell: (item) => (
                <span className="tabular-nums">
                    {formatMoney(item.current_base_value)}
                </span>
            ),
        },
        {
            key: 'gain_loss',
            label: 'Gain / loss',
            width: '150px',
            align: 'right',
            cell: (item) => (
                <span
                    className={
                        item.gain_loss > 0
                            ? 'font-semibold text-status-success tabular-nums'
                            : item.gain_loss < 0
                              ? 'font-semibold text-status-critical tabular-nums'
                              : 'font-semibold tabular-nums'
                    }
                >
                    {signedMoney(item.gain_loss)}
                </span>
            ),
        },
    ];

    const footerRows: EntityTableFooterRow[] = [
        {
            key: 'total',
            label: 'Total unrealised gain / loss',
            tone: 'strong',
            cells: {
                gain_loss: (
                    <span className="tabular-nums">
                        {signedMoney(totalGainLoss)}
                    </span>
                ),
            },
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={Globe}
            backHref="/finance/fx-revaluations"
            title="New FX revaluation"
            titleChip={
                <PageHeaderStatusChip variant="neutral">
                    Draft
                </PageHeaderStatusChip>
            }
            subline={`Unrealised foreign-exchange gain and loss as at ${revalDate(date)}`}
            actions={
                hasItems ? (
                    <PageHeaderPrimaryButton
                        icon={ArrowLeftRight}
                        disabled={processing}
                        onClick={() => handleSubmit()}
                    >
                        {processing ? 'Creating…' : 'Create draft revaluation'}
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Items to revalue"
                        ariaLabel="Jump to the revaluation preview"
                        onClick={scrollToPreview}
                    >
                        <PageHeaderMeterBig>
                            {preview.items.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            open foreign-currency bills and bank balances
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Currencies"
                        href="/finance/currencies"
                        ariaLabel="View currencies and their rates"
                    >
                        <PageHeaderMeterBig>{currencies}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            rates used for this calculation
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Unrealised gain / loss"
                        tone={isGain ? 'success' : 'critical'}
                        ariaLabel="Jump to the revaluation preview"
                        onClick={scrollToPreview}
                    >
                        <PageHeaderMeterBig>
                            {signedMoney(totalGainLoss)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterDelta
                            trend={isGain ? 'up' : 'down'}
                            good={isGain}
                        >
                            as at {revalDate(date)}
                        </PageHeaderMeterDelta>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <Popover
                    open={datePickerOpen}
                    onOpenChange={(next) => {
                        setDatePickerOpen(next);
                        if (next) setPendingDate(data.date);
                    }}
                >
                    <PopoverTrigger asChild>
                        <PageHeaderFilterButton
                            icon={CalendarRange}
                            active
                            aria-label="Change the revaluation date"
                        >
                            As at {revalDate(date)}
                        </PageHeaderFilterButton>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="w-60">
                        <form
                            className="flex flex-col gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyDate(pendingDate);
                            }}
                        >
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="reval-date">As-at date</Label>
                                <Input
                                    id="reval-date"
                                    type="date"
                                    value={pendingDate}
                                    onChange={(event) =>
                                        setPendingDate(event.target.value)
                                    }
                                />
                            </div>
                            <Button type="submit" size="sm">
                                Recalculate
                            </Button>
                        </form>
                    </PopoverContent>
                </Popover>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New FX revaluation" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {errors.date ? (
                        <p className="text-sm text-destructive">
                            {errors.date}
                        </p>
                    ) : null}

                    <div
                        ref={previewRef}
                        className="flex scroll-mt-5 flex-col gap-5"
                    >
                        <ListCaption
                            title="Revaluation preview"
                            caption={`${preview.items.length} open items · ${signedMoney(totalGainLoss)} unrealised`}
                        />

                        {!hasItems ? (
                            <EmptyState
                                icon={ArrowLeftRight}
                                heading="Nothing to revalue at this date"
                                description="There are no open foreign-currency bills or bank balances on this date. Pick another as-at date to recalculate."
                            />
                        ) : (
                            <EntityTable
                                rows={preview.items}
                                rowKey={(item) =>
                                    `${item.type}-${item.reference}`
                                }
                                identityLabel="Item"
                                minWidth={1200}
                                identity={(item) => ({
                                    icon: ArrowLeftRight,
                                    name: item.reference,
                                    subline:
                                        typeLabels[item.type] ?? item.type,
                                })}
                                columns={columns}
                                actionsFor={noActions}
                                footerRows={footerRows}
                            />
                        )}
                    </div>

                    {hasItems ? (
                        <Card className="rounded-[14px] p-5">
                            <form
                                onSubmit={handleSubmit}
                                className="flex flex-col gap-3"
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="reval-notes">
                                        Notes (optional)
                                    </Label>
                                    <Input
                                        id="reval-notes"
                                        value={data.notes}
                                        onChange={(e) =>
                                            setData('notes', e.target.value)
                                        }
                                        placeholder="Why this revaluation is being run…"
                                    />
                                    {errors.notes ? (
                                        <p className="text-sm text-destructive">
                                            {errors.notes}
                                        </p>
                                    ) : null}
                                </div>
                                <p className="text-[12.5px] text-muted-foreground">
                                    Creating the revaluation saves it as a
                                    draft. Posting it to the general ledger is a
                                    separate, confirmed step on the revaluation
                                    list.
                                </p>
                            </form>
                        </Card>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
