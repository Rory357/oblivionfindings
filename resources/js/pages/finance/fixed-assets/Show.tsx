import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    FixedAssetDialog,
    FixedAssetDisposeDialog,
    type EditableFixedAsset,
    type FixedAssetGlAccount,
} from '@/components/finance';
import { Cpu, Edit, Trash2 } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
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

interface LinkedAssetInfo {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string | null;
}

interface LinkedDeviceHealth {
    id: number;
    device_uid: string;
    name: string | null;
    domain: string | null;
    category: string | null;
    status: string | null;
    health_status: string | null;
    provider: string | null;
    last_seen_at: string | null;
    battery_level: number | null;
    link_type: string | null;
    detail_url: string | null;
}

interface Props {
    asset: FixedAsset;
    depreciationSchedule: ScheduleEntry[];
    hasDepreciations: boolean;
    canManage: boolean;
    assetAccounts: FixedAssetGlAccount[];
    expenseAccounts: FixedAssetGlAccount[];
    linkedAsset?: LinkedAssetInfo | null;
    linkedDevices?: LinkedDeviceHealth[];
}

const formatNZD = (amount: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

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

const statusLabels: Record<string, string> = {
    active: 'Active',
    fully_depreciated: 'Fully Depreciated',
    disposed: 'Disposed',
};

const statusColors: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    fully_depreciated: 'bg-status-warning-bg text-status-warning',
    disposed: 'bg-muted text-muted-foreground',
};

const methodLabels: Record<string, string> = {
    straight_line: 'Straight Line',
    diminishing_value: 'Diminishing Value',
};

export default function FixedAssetShow({ asset, depreciationSchedule, hasDepreciations, canManage, assetAccounts, expenseAccounts, linkedAsset, linkedDevices }: Props) {
    const devices = linkedDevices ?? [];
    const [editOpen, setEditOpen] = useState(false);
    const [disposeOpen, setDisposeOpen] = useState(false);

    const bookValue = Number(asset.purchase_cost) - Number(asset.accumulated_depreciation);

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
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/fixed-assets"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {asset.asset_name}
                                <Badge variant="secondary" className={categoryColors[asset.category] || ''}>
                                    {categoryLabels[asset.category] || asset.category}
                                </Badge>
                                <Badge variant="secondary" className={statusColors[asset.status] || ''}>
                                    {statusLabels[asset.status] || asset.status}
                                </Badge>
                            </span>
                        }
                        description={asset.asset_tag ? `Tag: ${asset.asset_tag}` : undefined}
                        actions={
                            canManage && asset.status === 'active' ? (
                                <>
                                    <Button variant="outline" onClick={() => setEditOpen(true)}>
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                    <Button variant="destructive" onClick={() => setDisposeOpen(true)}>
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
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Asset Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">Purchase Date</dt>
                                    <dd className="font-medium">{new Date(asset.purchase_date).toLocaleDateString('en-NZ')}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Depreciation Method</dt>
                                    <dd className="font-medium">{methodLabels[asset.depreciation_method] || asset.depreciation_method}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Useful Life</dt>
                                    <dd className="font-medium">{asset.useful_life_months} months ({(asset.useful_life_months / 12).toFixed(1)} years)</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Residual Value</dt>
                                    <dd className="font-medium font-mono">{formatNZD(asset.residual_value)}</dd>
                                </div>
                                {asset.gl_asset_account && (
                                    <div>
                                        <dt className="text-muted-foreground">Asset Account</dt>
                                        <dd className="font-medium">{asset.gl_asset_account.code} - {asset.gl_asset_account.name}</dd>
                                    </div>
                                )}
                                {asset.gl_depreciation_account && (
                                    <div>
                                        <dt className="text-muted-foreground">Accum. Depreciation Account</dt>
                                        <dd className="font-medium">{asset.gl_depreciation_account.code} - {asset.gl_depreciation_account.name}</dd>
                                    </div>
                                )}
                                {asset.gl_expense_account && (
                                    <div>
                                        <dt className="text-muted-foreground">Expense Account</dt>
                                        <dd className="font-medium">{asset.gl_expense_account.code} - {asset.gl_expense_account.name}</dd>
                                    </div>
                                )}
                                {asset.created_by && (
                                    <div>
                                        <dt className="text-muted-foreground">Created By</dt>
                                        <dd className="font-medium">{asset.created_by.name}</dd>
                                    </div>
                                )}
                                {asset.disposed_date && (
                                    <div>
                                        <dt className="text-muted-foreground">Disposal Date</dt>
                                        <dd className="font-medium">{new Date(asset.disposed_date).toLocaleDateString('en-NZ')}</dd>
                                    </div>
                                )}
                                {asset.disposal_proceeds !== null && (
                                    <div>
                                        <dt className="text-muted-foreground">Disposal Proceeds</dt>
                                        <dd className="font-medium font-mono">{formatNZD(asset.disposal_proceeds)}</dd>
                                    </div>
                                )}
                            </dl>
                            {asset.notes && (
                                <div className="mt-4 pt-4 border-t">
                                    <p className="text-sm text-muted-foreground mb-1">Notes</p>
                                    <p className="text-sm whitespace-pre-wrap">{asset.notes}</p>
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
                                    <span className="text-muted-foreground">Purchase Cost</span>
                                    <span className="font-mono tabular-nums font-medium">{formatNZD(asset.purchase_cost)}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Accumulated Depreciation</span>
                                    <span className="font-mono tabular-nums font-medium text-status-warning">
                                        -{formatNZD(asset.accumulated_depreciation)}
                                    </span>
                                </div>
                                <hr />
                                <div className="flex justify-between">
                                    <span className="font-medium">Book Value</span>
                                    <span className="text-xl font-bold font-mono tabular-nums">
                                        {formatNZD(bookValue)}
                                    </span>
                                </div>
                            </div>
                            {asset.status === 'active' && (
                                <div className="pt-2">
                                    <div className="w-full bg-muted rounded-full h-2">
                                        <div
                                            className="bg-primary rounded-full h-2 transition-all"
                                            style={{
                                                width: `${Math.min(100, (Number(asset.accumulated_depreciation) / (Number(asset.purchase_cost) - Number(asset.residual_value))) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1 text-center">
                                        {((Number(asset.accumulated_depreciation) / (Number(asset.purchase_cost) - Number(asset.residual_value))) * 100).toFixed(1)}% depreciated
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Device Health — read-only from canonical Security & Devices registry */}
                {(linkedAsset || devices.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Cpu className="h-4 w-4" />
                                Device Health
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {linkedAsset && (
                                <div className="mb-3 text-sm text-muted-foreground">
                                    Linked to asset:{' '}
                                    <span className="font-medium text-foreground">{linkedAsset.name}</span>
                                    {linkedAsset.asset_tag && (
                                        <span className="ml-1 font-mono text-xs">({linkedAsset.asset_tag})</span>
                                    )}
                                </div>
                            )}
                            {devices.length > 0 ? (
                                <div className="space-y-2">
                                    {devices.map((device) => (
                                        <a
                                            key={device.id}
                                            href={device.detail_url ?? '#'}
                                            className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{device.name ?? device.device_uid}</span>
                                                    <Badge variant="outline" className="font-mono text-[10px]">{device.device_uid}</Badge>
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {device.domain && <span>{device.domain.replace(/_/g, ' ')}</span>}
                                                    {device.category && <span>/ {device.category.replace(/_/g, ' ')}</span>}
                                                    {device.provider && <span>| {device.provider}</span>}
                                                    {device.last_seen_at && (
                                                        <span>Seen: {new Date(device.last_seen_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}</span>
                                                    )}
                                                    {device.battery_level !== null && <span>Battery: {device.battery_level}%</span>}
                                                </div>
                                            </div>
                                            <div className="flex flex-col items-end gap-1 shrink-0">
                                                <Badge
                                                    variant={device.status === 'active' ? 'default' : device.status === 'offline' ? 'secondary' : 'outline'}
                                                    className="text-[10px]"
                                                >
                                                    {device.status?.replace(/_/g, ' ') ?? 'unknown'}
                                                </Badge>
                                                {device.health_status && (
                                                    <Badge
                                                        variant={device.health_status === 'healthy' ? 'default' : device.health_status === 'critical' ? 'destructive' : 'outline'}
                                                        className="text-[10px]"
                                                    >
                                                        {device.health_status}
                                                    </Badge>
                                                )}
                                            </div>
                                        </a>
                                    ))}
                                </div>
                            ) : linkedAsset ? (
                                <p className="text-sm text-muted-foreground italic">
                                    No devices linked to this asset. Manage device links in{' '}
                                    <a href="/security-devices/devices" className="text-primary hover:underline">Security &amp; Devices</a>.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                )}

                {/* Depreciation History */}
                <Card>
                    <CardHeader>
                        <CardTitle>Depreciation History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {asset.depreciations.length === 0 ? (
                            <p className="text-sm text-muted-foreground text-center py-6">
                                No depreciation records yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Accumulated Total</TableHead>
                                        <TableHead className="text-right">Book Value After</TableHead>
                                        <TableHead>Journal</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {asset.depreciations.map((dep) => (
                                        <TableRow key={dep.id}>
                                            <TableCell className="text-sm">
                                                {new Date(dep.depreciation_date).toLocaleDateString('en-NZ')}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm">
                                                {formatNZD(dep.amount)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm">
                                                {formatNZD(dep.accumulated_total)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm font-medium">
                                                {formatNZD(dep.book_value_after)}
                                            </TableCell>
                                            <TableCell>
                                                {dep.journal ? (
                                                    <Link
                                                        href={`/finance/journals/${dep.journal.id}`}
                                                        className="text-sm font-mono text-primary hover:underline"
                                                    >
                                                        {dep.journal.journal_number}
                                                    </Link>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">-</span>
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
                            <CardTitle>Projected Depreciation Schedule</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Month</TableHead>
                                        <TableHead className="text-right">Depreciation Amount</TableHead>
                                        <TableHead className="text-right">Accumulated</TableHead>
                                        <TableHead className="text-right">Book Value</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {depreciationSchedule.map((entry, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="text-sm">{entry.month}</TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm">
                                                {formatNZD(entry.depreciation_amount)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm">
                                                {formatNZD(entry.accumulated)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm font-medium">
                                                {formatNZD(entry.book_value)}
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
                <FixedAssetDisposeDialog
                    open={disposeOpen}
                    onClose={() => setDisposeOpen(false)}
                    asset={{
                        id: asset.id,
                        asset_name: asset.asset_name,
                        asset_tag: asset.asset_tag,
                        purchase_cost: asset.purchase_cost,
                        accumulated_depreciation: asset.accumulated_depreciation,
                        gl_asset_account: asset.gl_asset_account
                            ? { code: asset.gl_asset_account.code, name: asset.gl_asset_account.name }
                            : null,
                        gl_depreciation_account: asset.gl_depreciation_account
                            ? { code: asset.gl_depreciation_account.code, name: asset.gl_depreciation_account.name }
                            : null,
                    }}
                />
            )}
        </AppLayout>
    );
}
