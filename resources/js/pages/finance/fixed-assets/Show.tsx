import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { ArrowLeft, Edit, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

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
    vehicle: 'bg-blue-100 text-blue-800',
    equipment: 'bg-purple-100 text-purple-800',
    building: 'bg-amber-100 text-amber-800',
    furniture: 'bg-teal-100 text-teal-800',
    it_equipment: 'bg-indigo-100 text-indigo-800',
    land: 'bg-green-100 text-green-800',
};

const statusLabels: Record<string, string> = {
    active: 'Active',
    fully_depreciated: 'Fully Depreciated',
    disposed: 'Disposed',
};

const statusColors: Record<string, string> = {
    active: 'bg-green-100 text-green-800',
    fully_depreciated: 'bg-amber-100 text-amber-800',
    disposed: 'bg-gray-100 text-gray-600',
};

const methodLabels: Record<string, string> = {
    straight_line: 'Straight Line',
    diminishing_value: 'Diminishing Value',
};

export default function FixedAssetShow({ asset, depreciationSchedule }: Props) {
    const [disposeModalOpen, setDisposeModalOpen] = useState(false);

    const disposeForm = useForm({
        disposed_date: new Date().toISOString().split('T')[0],
        disposal_proceeds: '',
    });

    const bookValue = Number(asset.purchase_cost) - Number(asset.accumulated_depreciation);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Fixed Assets', href: '/finance/fixed-assets' },
        { title: asset.asset_name, href: `/finance/fixed-assets/${asset.id}` },
    ];

    function handleDispose(e: FormEvent) {
        e.preventDefault();
        disposeForm.post(`/finance/fixed-assets/${asset.id}/dispose`, {
            onSuccess: () => setDisposeModalOpen(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={asset.asset_name} />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={'/finance/fixed-assets'}>
                            <Button variant="ghost" size="icon">
                                <ArrowLeft className="h-4 w-4" />
                            </Button>
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-2xl font-bold tracking-tight">{asset.asset_name}</h1>
                                <Badge variant="secondary" className={categoryColors[asset.category] || ''}>
                                    {categoryLabels[asset.category] || asset.category}
                                </Badge>
                                <Badge variant="secondary" className={statusColors[asset.status] || ''}>
                                    {statusLabels[asset.status] || asset.status}
                                </Badge>
                            </div>
                            {asset.asset_tag && (
                                <p className="text-muted-foreground font-mono text-sm mt-1">Tag: {asset.asset_tag}</p>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        {asset.status === 'active' && (
                            <>
                                <Link href={`/finance/fixed-assets/${asset.id}/edit`}>
                                    <Button variant="outline">
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                                <Dialog open={disposeModalOpen} onOpenChange={setDisposeModalOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive">
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Dispose
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Dispose Asset</DialogTitle>
                                            <DialogDescription>
                                                Record the disposal of this asset. This will post a GL journal with any gain
                                                or loss on disposal. This action cannot be undone.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={handleDispose}>
                                            <div className="space-y-4 py-4">
                                                <div className="rounded-lg bg-muted/50 p-3 text-sm">
                                                    <p>Current book value: <strong>{formatNZD(bookValue)}</strong></p>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="disposed_date">Disposal Date *</Label>
                                                    <Input
                                                        id="disposed_date"
                                                        type="date"
                                                        value={disposeForm.data.disposed_date}
                                                        onChange={(e) => disposeForm.setData('disposed_date', e.target.value)}
                                                    />
                                                    {disposeForm.errors.disposed_date && (
                                                        <p className="text-sm text-destructive">{disposeForm.errors.disposed_date}</p>
                                                    )}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="disposal_proceeds">Disposal Proceeds (NZD) *</Label>
                                                    <Input
                                                        id="disposal_proceeds"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={disposeForm.data.disposal_proceeds}
                                                        onChange={(e) => disposeForm.setData('disposal_proceeds', e.target.value)}
                                                        placeholder="0.00"
                                                    />
                                                    {disposeForm.errors.disposal_proceeds && (
                                                        <p className="text-sm text-destructive">{disposeForm.errors.disposal_proceeds}</p>
                                                    )}
                                                </div>
                                                {disposeForm.data.disposal_proceeds && (
                                                    <div className="rounded-lg bg-muted/50 p-3 text-sm">
                                                        {(() => {
                                                            const gainLoss = Number(disposeForm.data.disposal_proceeds) - bookValue;
                                                            return (
                                                                <p>
                                                                    {gainLoss >= 0 ? 'Gain' : 'Loss'} on disposal:{' '}
                                                                    <strong className={gainLoss >= 0 ? 'text-emerald-600' : 'text-destructive'}>
                                                                        {formatNZD(Math.abs(gainLoss))}
                                                                    </strong>
                                                                </p>
                                                            );
                                                        })()}
                                                    </div>
                                                )}
                                            </div>
                                            <DialogFooter>
                                                <Button type="button" variant="outline" onClick={() => setDisposeModalOpen(false)}>
                                                    Cancel
                                                </Button>
                                                <Button type="submit" variant="destructive" disabled={disposeForm.processing}>
                                                    {disposeForm.processing ? 'Processing...' : 'Dispose Asset'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            </>
                        )}
                    </div>
                </div>

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
                                    <span className="font-mono tabular-nums font-medium text-amber-600">
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
            </div>
        </AppLayout>
    );
}
