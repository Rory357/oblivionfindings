import {
    AssetFinanceTechnologyProjectionPanel,
    type AssetFinanceTechnologyProjection,
} from '@/components/assets/asset-finance-technology-projection';
import {
    ConfirmDialog,
    FinanceSectionRail,
    FixedAssetDialog,
    FixedAssetDisposeDialog,
    formatMoney,
    type EditableFixedAsset,
    type FixedAssetGlAccount,
} from '@/components/finance';
import {
    EmptyValue,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    BookCheck,
    Package,
    PackageMinus,
    Pencil,
    TrendingDown,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface Journal {
    id: number;
    journal_number: string;
}

interface Depreciation {
    id: number;
    depreciation_date: string;
    amount: string;
    accumulated_total: string;
    book_value_after: string;
    journal: Journal | null;
    reversal_journal: Journal | null;
}

interface FixedAsset {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    category: string;
    purchase_date: string;
    purchase_cost: string;
    residual_value: string;
    useful_life_months: number;
    depreciation_method: string;
    accumulated_depreciation: string;
    status: string;
    disposed_date: string | null;
    disposal_proceeds: string | null;
    notes: string | null;
    gl_asset_account_id: number | null;
    gl_depreciation_account_id: number | null;
    gl_expense_account_id: number | null;
    acquisition_journal_id: number | null;
    gl_asset_account: GlAccount | null;
    gl_depreciation_account: GlAccount | null;
    gl_expense_account: GlAccount | null;
    created_by: { id: number; name: string } | null;
    depreciations: Depreciation[];
    created_at: string;
}

interface ScheduleEntry {
    month: string;
    depreciation_amount: number;
    accumulated: number;
    book_value: number;
}

interface Props {
    asset: FixedAsset;
    depreciationSchedule: ScheduleEntry[];
    hasDepreciations: boolean;
    canManage: boolean;
    assetAccounts: FixedAssetGlAccount[];
    expenseAccounts: FixedAssetGlAccount[];
    assetReconciliation: AssetFinanceTechnologyProjection;
}

const categoryLabels: Record<string, string> = {
    vehicle: 'Vehicle',
    equipment: 'Equipment',
    building: 'Building',
    furniture: 'Furniture',
    it_equipment: 'IT equipment',
    land: 'Land',
};

const methodLabels: Record<string, string> = {
    straight_line: 'Straight line',
    diminishing_value: 'Diminishing value',
};

const assetDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

export default function FixedAssetShow({
    asset,
    depreciationSchedule,
    hasDepreciations,
    canManage,
    assetAccounts,
    expenseAccounts,
    assetReconciliation,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [disposeOpen, setDisposeOpen] = useState(false);
    const [capitaliseOpen, setCapitaliseOpen] = useState(false);
    const [capitalising, setCapitalising] = useState(false);

    // Captured-at-source assets register without GL accounts; once the GL asset
    // account is assigned, the acquisition journal is posted explicitly here.
    const needsCapitalisation =
        !!asset.gl_asset_account_id && !asset.acquisition_journal_id;

    const purchaseCost = Number(asset.purchase_cost);
    const accumulated = Number(asset.accumulated_depreciation);
    const residual = Number(asset.residual_value);
    const bookValue = purchaseCost - accumulated;
    const depreciableBase = purchaseCost - residual;
    const depreciatedPercent =
        depreciableBase > 0 ? (accumulated / depreciableBase) * 100 : 0;
    const monthsRemaining = depreciationSchedule.length;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'General ledger', href: '/finance/ledger' },
        { title: 'Fixed assets', href: '/finance/fixed-assets' },
        { title: asset.asset_name },
    ];

    const editableAsset: EditableFixedAsset = {
        id: asset.id,
        asset_name: asset.asset_name,
        asset_tag: asset.asset_tag,
        category: asset.category,
        purchase_date: asset.purchase_date,
        purchase_cost: asset.purchase_cost,
        residual_value: asset.residual_value,
        useful_life_months: asset.useful_life_months,
        depreciation_method: asset.depreciation_method,
        gl_asset_account_id: asset.gl_asset_account_id,
        gl_depreciation_account_id: asset.gl_depreciation_account_id,
        gl_expense_account_id: asset.gl_expense_account_id,
        notes: asset.notes,
        has_depreciations: hasDepreciations,
    };

    const meta: { label: string; value: ReactNode }[] = [
        { label: 'Purchase date', value: assetDate(asset.purchase_date) },
        {
            label: 'Depreciation method',
            value:
                methodLabels[asset.depreciation_method] ??
                asset.depreciation_method,
        },
        {
            label: 'Useful life',
            value: `${asset.useful_life_months} months (${(asset.useful_life_months / 12).toFixed(1)} years)`,
        },
        { label: 'Residual value', value: formatMoney(asset.residual_value) },
        {
            label: 'Asset account',
            value: asset.gl_asset_account
                ? `${asset.gl_asset_account.code} — ${asset.gl_asset_account.name}`
                : '—',
        },
        {
            label: 'Accum. depreciation account',
            value: asset.gl_depreciation_account
                ? `${asset.gl_depreciation_account.code} — ${asset.gl_depreciation_account.name}`
                : '—',
        },
        {
            label: 'Depreciation expense account',
            value: asset.gl_expense_account
                ? `${asset.gl_expense_account.code} — ${asset.gl_expense_account.name}`
                : '—',
        },
        { label: 'Created by', value: asset.created_by?.name ?? '—' },
        ...(asset.disposed_date
            ? [
                  {
                      label: 'Disposal date',
                      value: assetDate(asset.disposed_date),
                  },
              ]
            : []),
        ...(asset.disposal_proceeds !== null
            ? [
                  {
                      label: 'Disposal proceeds',
                      value: formatMoney(asset.disposal_proceeds),
                  },
              ]
            : []),
    ];

    /* ---------------- Tables ---------------- */

    const depreciationActions = (dep: Depreciation): MenuItem[] => {
        const items: MenuItem[] = [];
        if (dep.journal) {
            items.push({
                label: `Open journal ${dep.journal.journal_number}`,
                icon: BookCheck,
                onClick: () =>
                    router.visit(`/finance/journals/${dep.journal?.id}`),
            });
        }
        if (dep.reversal_journal) {
            items.push({
                label: `Open reversal ${dep.reversal_journal.journal_number}`,
                icon: BookCheck,
                onClick: () =>
                    router.visit(
                        `/finance/journals/${dep.reversal_journal?.id}`,
                    ),
            });
        }
        return items;
    };

    const depreciationColumns: EntityTableColumn<Depreciation>[] = [
        {
            key: 'amount',
            label: 'Amount',
            width: '150px',
            align: 'right',
            cell: (dep) => (
                <span className="tabular-nums">{formatMoney(dep.amount)}</span>
            ),
        },
        {
            key: 'accumulated',
            label: 'Accumulated',
            width: '160px',
            align: 'right',
            cell: (dep) => (
                <span className="tabular-nums">
                    {formatMoney(dep.accumulated_total)}
                </span>
            ),
        },
        {
            key: 'book_value',
            label: 'Book value after',
            width: '170px',
            align: 'right',
            cell: (dep) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(dep.book_value_after)}
                </span>
            ),
        },
        {
            key: 'journal',
            label: 'Journal',
            width: '150px',
            cell: (dep) =>
                dep.journal ? (
                    <span className="truncate">
                        {dep.journal.journal_number}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'correction',
            label: 'Correction',
            width: '210px',
            cell: (dep) =>
                dep.reversal_journal ? (
                    <span className="flex min-w-0 items-center gap-2">
                        <StatusBadge status="reversed" size="sm" />
                        <span className="truncate text-muted-foreground">
                            {dep.reversal_journal.journal_number}
                        </span>
                    </span>
                ) : dep.journal ? (
                    <StatusBadge status="posted" size="sm" />
                ) : (
                    <StatusBadge
                        status="recorded"
                        label="Recorded (no GL)"
                        size="sm"
                    />
                ),
        },
    ];

    const scheduleActions = (): MenuItem[] => [];

    const scheduleColumns: EntityTableColumn<ScheduleEntry>[] = [
        {
            key: 'amount',
            label: 'Depreciation',
            width: '170px',
            align: 'right',
            cell: (entry) => (
                <span className="tabular-nums">
                    {formatMoney(entry.depreciation_amount)}
                </span>
            ),
        },
        {
            key: 'accumulated',
            label: 'Accumulated',
            width: '170px',
            align: 'right',
            cell: (entry) => (
                <span className="tabular-nums">
                    {formatMoney(entry.accumulated)}
                </span>
            ),
        },
        {
            key: 'book_value',
            label: 'Book value',
            width: '170px',
            align: 'right',
            cell: (entry) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(entry.book_value)}
                </span>
            ),
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            variant="profile"
            icon={Package}
            backHref="/finance/fixed-assets"
            title={asset.asset_name}
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        asset.status === 'active'
                            ? 'success'
                            : asset.status === 'disposed'
                              ? 'neutral'
                              : 'info'
                    }
                >
                    {asset.status === 'fully_depreciated'
                        ? 'Fully depreciated'
                        : asset.status.charAt(0).toUpperCase() +
                          asset.status.slice(1)}
                </PageHeaderStatusChip>
            }
            subline={[
                categoryLabels[asset.category] ?? asset.category,
                asset.asset_tag ? `Tag ${asset.asset_tag}` : null,
                methodLabels[asset.depreciation_method] ??
                    asset.depreciation_method,
                `Purchased ${assetDate(asset.purchase_date)}`,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                canManage && asset.status === 'active' ? (
                    <>
                        {needsCapitalisation ? (
                            <PageHeaderGlassButton
                                icon={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit asset
                            </PageHeaderGlassButton>
                        ) : null}
                        <PageHeaderGlassButton
                            icon={PackageMinus}
                            onClick={() => setDisposeOpen(true)}
                        >
                            Dispose
                        </PageHeaderGlassButton>
                        {/* Capitalising is the one thing that must happen next
                         * when it is outstanding, so it takes the primary. */}
                        {needsCapitalisation ? (
                            <PageHeaderPrimaryButton
                                icon={BookCheck}
                                onClick={() => setCapitaliseOpen(true)}
                            >
                                Post acquisition
                            </PageHeaderPrimaryButton>
                        ) : (
                            <PageHeaderPrimaryButton
                                icon={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit asset
                            </PageHeaderPrimaryButton>
                        )}
                    </>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Purchase cost"
                        href="/finance/fixed-assets"
                        ariaLabel="View the fixed-asset register"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(asset.purchase_cost)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            residual {formatMoney(asset.residual_value)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Depreciation"
                        tone="warning"
                        href="/finance/journals"
                        ariaLabel="View the depreciation journals"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(asset.accumulated_depreciation)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={depreciatedPercent} />
                        <PageHeaderMeterCaption>
                            {depreciatedPercent.toFixed(1)}% of the depreciable
                            base
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Book value"
                        tone="success"
                        href="/finance/reports/balance-sheet"
                        ariaLabel="View the balance sheet"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(bookValue)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            carried on the balance sheet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Months remaining"
                        ariaLabel="View this asset's projected depreciation"
                        href={`/finance/fixed-assets/${asset.id}`}
                    >
                        <PageHeaderMeterBig>
                            {monthsRemaining}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {asset.useful_life_months} months of useful life
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={asset.asset_name} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <Card className="rounded-[14px] p-5">
                        <dl className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
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
                        {asset.notes ? (
                            <div className="mt-5 border-t border-border pt-4">
                                <p className="text-[11.5px] text-muted-foreground">
                                    Notes
                                </p>
                                <p className="text-[13px] whitespace-pre-wrap">
                                    {asset.notes}
                                </p>
                            </div>
                        ) : null}
                    </Card>

                    <AssetFinanceTechnologyProjectionPanel
                        projection={assetReconciliation}
                    />

                    <ListCaption
                        title="Depreciation history"
                        caption={`${asset.depreciations.length} recorded ${
                            asset.depreciations.length === 1
                                ? 'period'
                                : 'periods'
                        }`}
                    />
                    {asset.depreciations.length === 0 ? (
                        <EmptyState
                            variant="compact"
                            icon={TrendingDown}
                            heading="No depreciation recorded yet"
                            description="Depreciation appears here once a monthly run includes this asset."
                        />
                    ) : (
                        <EntityTable
                            rows={asset.depreciations}
                            rowKey={(dep) => dep.id}
                            identityLabel="Period"
                            minWidth={1020}
                            identity={(dep) => ({
                                icon: TrendingDown,
                                name: assetDate(dep.depreciation_date),
                            })}
                            columns={depreciationColumns}
                            actionsFor={depreciationActions}
                        />
                    )}

                    {depreciationSchedule.length > 0 ? (
                        <>
                            <ListCaption
                                title="Projected depreciation schedule"
                                caption={`${depreciationSchedule.length} months remaining`}
                            />
                            <EntityTable
                                rows={depreciationSchedule}
                                rowKey={(entry) => entry.month}
                                identityLabel="Month"
                                minWidth={860}
                                identity={(entry) => ({
                                    icon: TrendingDown,
                                    name: entry.month,
                                })}
                                columns={scheduleColumns}
                                actionsFor={scheduleActions}
                            />
                        </>
                    ) : null}
                </div>
            </PageLayout>

            {canManage ? (
                <FixedAssetDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    asset={editableAsset}
                    assetAccounts={assetAccounts}
                    expenseAccounts={expenseAccounts}
                />
            ) : null}

            {canManage ? (
                <ConfirmDialog
                    variant="default"
                    open={capitaliseOpen}
                    onClose={() => setCapitaliseOpen(false)}
                    title="Post the acquisition journal?"
                    description={
                        <>
                            This posts a journal to the ledger for{' '}
                            <span className="font-medium text-foreground">
                                {asset.asset_name}
                            </span>
                            : DR{' '}
                            {asset.gl_asset_account
                                ? `${asset.gl_asset_account.code} ${asset.gl_asset_account.name}`
                                : 'the GL asset account'}{' '}
                            / CR 1000 Bank for{' '}
                            {formatMoney(purchaseCost)}. It can only happen
                            once.
                        </>
                    }
                    confirmText="Post acquisition"
                    processing={capitalising}
                    onConfirm={() => {
                        setCapitalising(true);
                        router.post(
                            `/finance/fixed-assets/${asset.id}/capitalise`,
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => {
                                    setCapitalising(false);
                                    setCapitaliseOpen(false);
                                },
                            },
                        );
                    }}
                />
            ) : null}

            {canManage ? (
                <FixedAssetDisposeDialog
                    open={disposeOpen}
                    onClose={() => setDisposeOpen(false)}
                    asset={{
                        id: asset.id,
                        asset_name: asset.asset_name,
                        asset_tag: asset.asset_tag,
                        purchase_cost: asset.purchase_cost,
                        accumulated_depreciation:
                            asset.accumulated_depreciation,
                        gl_asset_account: asset.gl_asset_account
                            ? {
                                  code: asset.gl_asset_account.code,
                                  name: asset.gl_asset_account.name,
                              }
                            : null,
                        gl_depreciation_account: asset.gl_depreciation_account
                            ? {
                                  code: asset.gl_depreciation_account.code,
                                  name: asset.gl_depreciation_account.name,
                              }
                            : null,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
