import {
    AssetFinanceTechnologyProjectionPanel,
    type AssetFinanceTechnologyProjection,
} from '@/components/assets/asset-finance-technology-projection';
import {
    ConfirmDialog,
    FixedAssetDialog,
    FixedAssetDisposeDialog,
    formatMoney,
    type EditableFixedAsset,
    type FixedAssetGlAccount,
} from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BookCheck, Edit, Trash2 } from 'lucide-react';
import { useState } from 'react';

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
    it_equipment: 'IT Equipment',
    land: 'Land',
};

const categoryColors: Record<string, string> = {
    vehicle: 'bg-status-info-bg text-status-info',
    equipment: 'bg-primary/10 text-primary',
    building: 'bg-status-warning-bg text-status-warning',
    furniture: 'bg-status-info-bg text-status-info',
    it_equipment: 'bg-primary/10 text-primary',
    land: 'bg-status-success-bg text-status-success',
};

const methodLabels: Record<string, string> = {
    straight_line: 'Straight Line',
    diminishing_value: 'Diminishing Value',
};

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

    // Captured-at-source assets register without GL accounts; once the GL asset
    // account is assigned, the acquisition journal is posted explicitly here.
    const needsCapitalisation =
        !!asset.gl_asset_account_id && !asset.acquisition_journal_id;

    const bookValue =
        Number(asset.purchase_cost) - Number(asset.accumulated_depreciation);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Fixed Assets', href: '/finance/fixed-assets' },
        { title: asset.asset_name, href: `/finance/fixed-assets/${asset.id}` },
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={asset.asset_name} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/fixed-assets"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {asset.asset_name}
                                <Badge
                                    variant="secondary"
                                    className={
                                        categoryColors[asset.category] || ''
                                    }
                                >
                                    {categoryLabels[asset.category] ||
                                        asset.category}
                                </Badge>
                                <StatusBadge status={asset.status} />
                            </span>
                        }
                        description={
                            asset.asset_tag
                                ? `Tag: ${asset.asset_tag}`
                                : undefined
                        }
                        actions={
                            canManage && asset.status === 'active' ? (
                                <>
                                    {needsCapitalisation && (
                                        <Button
                                            onClick={() =>
                                                setCapitaliseOpen(true)
                                            }
                                        >
                                            <BookCheck className="mr-2 h-4 w-4" />
                                            Post acquisition
                                        </Button>
                                    )}
                                    <Button
                                        variant="outline"
                                        onClick={() => setEditOpen(true)}
                                    >
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        onClick={() => setDisposeOpen(true)}
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        Dispose
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Asset Details + Book Value */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Asset Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Purchase Date
                                    </dt>
                                    <dd className="font-medium">
                                        {new Date(
                                            asset.purchase_date,
                                        ).toLocaleDateString('en-NZ')}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Depreciation Method
                                    </dt>
                                    <dd className="font-medium">
                                        {methodLabels[
                                            asset.depreciation_method
                                        ] || asset.depreciation_method}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Useful Life
                                    </dt>
                                    <dd className="font-medium">
                                        {asset.useful_life_months} months (
                                        {(
                                            asset.useful_life_months / 12
                                        ).toFixed(1)}{' '}
                                        years)
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Residual Value
                                    </dt>
                                    <dd className="font-mono font-medium">
                                        {formatMoney(asset.residual_value)}
                                    </dd>
                                </div>
                                {asset.gl_asset_account && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Asset Account
                                        </dt>
                                        <dd className="font-medium">
                                            {asset.gl_asset_account.code} -{' '}
                                            {asset.gl_asset_account.name}
                                        </dd>
                                    </div>
                                )}
                                {asset.gl_depreciation_account && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Accum. Depreciation Account
                                        </dt>
                                        <dd className="font-medium">
                                            {asset.gl_depreciation_account.code}{' '}
                                            -{' '}
                                            {asset.gl_depreciation_account.name}
                                        </dd>
                                    </div>
                                )}
                                {asset.gl_expense_account && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Expense Account
                                        </dt>
                                        <dd className="font-medium">
                                            {asset.gl_expense_account.code} -{' '}
                                            {asset.gl_expense_account.name}
                                        </dd>
                                    </div>
                                )}
                                {asset.created_by && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Created By
                                        </dt>
                                        <dd className="font-medium">
                                            {asset.created_by.name}
                                        </dd>
                                    </div>
                                )}
                                {asset.disposed_date && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Disposal Date
                                        </dt>
                                        <dd className="font-medium">
                                            {new Date(
                                                asset.disposed_date,
                                            ).toLocaleDateString('en-NZ')}
                                        </dd>
                                    </div>
                                )}
                                {asset.disposal_proceeds !== null && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Disposal Proceeds
                                        </dt>
                                        <dd className="font-mono font-medium">
                                            {formatMoney(
                                                asset.disposal_proceeds,
                                            )}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            {asset.notes && (
                                <div className="mt-4 border-t pt-4">
                                    <p className="mb-1 text-sm text-muted-foreground">
                                        Notes
                                    </p>
                                    <p className="text-sm whitespace-pre-wrap">
                                        {asset.notes}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Book Value Card */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Book Value</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-3">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Purchase Cost
                                    </span>
                                    <span className="font-mono font-medium tabular-nums">
                                        {formatMoney(asset.purchase_cost)}
                                    </span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Accumulated Depreciation
                                    </span>
                                    <span className="font-mono font-medium text-status-warning tabular-nums">
                                        -
                                        {formatMoney(
                                            asset.accumulated_depreciation,
                                        )}
                                    </span>
                                </div>
                                <hr />
                                <div className="flex justify-between">
                                    <span className="font-medium">
                                        Book Value
                                    </span>
                                    <span className="font-mono text-xl font-bold tabular-nums">
                                        {formatMoney(bookValue)}
                                    </span>
                                </div>
                            </div>
                            {asset.status === 'active' && (
                                <div className="pt-2">
                                    <div className="h-2 w-full rounded-full bg-muted">
                                        <div
                                            className="h-2 rounded-full bg-primary transition-all"
                                            style={{
                                                width: `${Math.min(100, (Number(asset.accumulated_depreciation) / (Number(asset.purchase_cost) - Number(asset.residual_value))) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="mt-1 text-center text-xs text-muted-foreground">
                                        {(
                                            (Number(
                                                asset.accumulated_depreciation,
                                            ) /
                                                (Number(asset.purchase_cost) -
                                                    Number(
                                                        asset.residual_value,
                                                    ))) *
                                            100
                                        ).toFixed(1)}
                                        % depreciated
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <AssetFinanceTechnologyProjectionPanel
                    projection={assetReconciliation}
                />

                {/* Depreciation History */}
                <Card>
                    <CardHeader>
                        <CardTitle>Depreciation History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {asset.depreciations.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No depreciation records yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Accumulated Total
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Book Value After
                                        </TableHead>
                                        <TableHead>Journal</TableHead>
                                        <TableHead>Correction</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {asset.depreciations.map((dep) => (
                                        <TableRow key={dep.id}>
                                            <TableCell className="text-sm">
                                                {new Date(
                                                    dep.depreciation_date,
                                                ).toLocaleDateString('en-NZ')}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {formatMoney(dep.amount)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {formatMoney(
                                                    dep.accumulated_total,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm font-medium tabular-nums">
                                                {formatMoney(
                                                    dep.book_value_after,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {dep.journal ? (
                                                    <Link
                                                        href={`/finance/journals/${dep.journal.id}`}
                                                        className="font-mono text-sm text-primary hover:underline"
                                                    >
                                                        {
                                                            dep.journal
                                                                .journal_number
                                                        }
                                                    </Link>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">
                                                        -
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {dep.reversal_journal ? (
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge
                                                            status="reversed"
                                                            size="sm"
                                                        />
                                                        <Link
                                                            href={`/finance/journals/${dep.reversal_journal.id}`}
                                                            className="font-mono text-sm text-primary hover:underline"
                                                        >
                                                            {
                                                                dep
                                                                    .reversal_journal
                                                                    .journal_number
                                                            }
                                                        </Link>
                                                    </div>
                                                ) : dep.journal ? (
                                                    <StatusBadge
                                                        status="posted"
                                                        size="sm"
                                                    />
                                                ) : (
                                                    <StatusBadge
                                                        status="recorded"
                                                        label="Recorded (no GL)"
                                                        size="sm"
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Projected Depreciation Schedule */}
                {depreciationSchedule.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Projected Depreciation Schedule
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Month</TableHead>
                                        <TableHead className="text-right">
                                            Depreciation Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Accumulated
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Book Value
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {depreciationSchedule.map((entry, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="text-sm">
                                                {entry.month}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {formatMoney(
                                                    entry.depreciation_amount,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm tabular-nums">
                                                {formatMoney(entry.accumulated)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-sm font-medium tabular-nums">
                                                {formatMoney(entry.book_value)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            {canManage && (
                <FixedAssetDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    asset={editableAsset}
                    assetAccounts={assetAccounts}
                    expenseAccounts={expenseAccounts}
                />
            )}

            {canManage && (
                <ConfirmDialog
                    open={capitaliseOpen}
                    onOpenChange={setCapitaliseOpen}
                    title="Post acquisition journal"
                    description={
                        <>
                            Posts the acquisition journal for{' '}
                            <span className="font-medium">
                                {asset.asset_name}
                            </span>
                            : DR{' '}
                            {asset.gl_asset_account
                                ? `${asset.gl_asset_account.code} ${asset.gl_asset_account.name}`
                                : 'the GL asset account'}{' '}
                            / CR 1000 Bank for{' '}
                            {formatMoney(Number(asset.purchase_cost))}. This
                            posts to the general ledger and can only happen
                            once.
                        </>
                    }
                    confirmLabel="Post acquisition"
                    onConfirm={() =>
                        router.post(
                            `/finance/fixed-assets/${asset.id}/capitalise`,
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setCapitaliseOpen(false),
                            },
                        )
                    }
                />
            )}

            {canManage && (
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
            )}
        </AppLayout>
    );
}
