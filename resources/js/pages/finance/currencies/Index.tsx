import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Coins, Plus, Pencil, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Currency = {
    id: number;
    code: string;
    name: string;
    symbol: string;
    decimal_places: number;
    exchange_rate: string;
    rate_updated_at: string | null;
    is_base: boolean;
    is_active: boolean;
};

type PageProps = {
    currencies: Currency[];
};

const formatDate = (dateStr: string | null) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

function CreateCurrencyDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        name: '',
        symbol: '',
        decimal_places: 2,
        exchange_rate: '1.000000',
        is_base: false,
        is_active: true,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/currencies', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Add Currency
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Create Currency</DialogTitle>
                    <DialogDescription>Add a new currency for multi-currency transactions.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="curr-code">Code (ISO 4217) *</Label>
                            <Input
                                id="curr-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="e.g. AUD"
                                maxLength={3}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="curr-name">Name *</Label>
                            <Input
                                id="curr-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Australian Dollar"
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="curr-symbol">Symbol *</Label>
                            <Input
                                id="curr-symbol"
                                value={data.symbol}
                                onChange={(e) => setData('symbol', e.target.value)}
                                placeholder="e.g. A$"
                                maxLength={10}
                            />
                            {errors.symbol && <p className="text-sm text-destructive">{errors.symbol}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="curr-decimals">Decimal Places</Label>
                            <Input
                                id="curr-decimals"
                                type="number"
                                min={0}
                                max={6}
                                value={data.decimal_places}
                                onChange={(e) => setData('decimal_places', parseInt(e.target.value) || 2)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="curr-rate">Rate to NZD *</Label>
                            <Input
                                id="curr-rate"
                                type="number"
                                step="0.000001"
                                min="0.000001"
                                value={data.exchange_rate}
                                onChange={(e) => setData('exchange_rate', e.target.value)}
                            />
                            {errors.exchange_rate && <p className="text-sm text-destructive">{errors.exchange_rate}</p>}
                        </div>
                    </div>
                    <div className="flex items-center gap-6">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="curr-base"
                                checked={data.is_base}
                                onCheckedChange={(checked) => setData('is_base', checked === true)}
                            />
                            <Label htmlFor="curr-base" className="font-normal">Base Currency</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="curr-active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked === true)}
                            />
                            <Label htmlFor="curr-active" className="font-normal">Active</Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditCurrencyDialog({ currency }: { currency: Currency }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        code: currency.code,
        name: currency.name,
        symbol: currency.symbol,
        decimal_places: currency.decimal_places,
        exchange_rate: currency.exchange_rate,
        is_base: currency.is_base,
        is_active: currency.is_active,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/currencies/${currency.id}`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon">
                    <Pencil className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Currency</DialogTitle>
                    <DialogDescription>Update currency details and exchange rate.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-curr-code">Code (ISO 4217) *</Label>
                            <Input
                                id="edit-curr-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                maxLength={3}
                            />
                            {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-curr-name">Name *</Label>
                            <Input
                                id="edit-curr-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-curr-symbol">Symbol *</Label>
                            <Input
                                id="edit-curr-symbol"
                                value={data.symbol}
                                onChange={(e) => setData('symbol', e.target.value)}
                                maxLength={10}
                            />
                            {errors.symbol && <p className="text-sm text-destructive">{errors.symbol}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-curr-decimals">Decimal Places</Label>
                            <Input
                                id="edit-curr-decimals"
                                type="number"
                                min={0}
                                max={6}
                                value={data.decimal_places}
                                onChange={(e) => setData('decimal_places', parseInt(e.target.value) || 2)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-curr-rate">Rate to NZD *</Label>
                            <Input
                                id="edit-curr-rate"
                                type="number"
                                step="0.000001"
                                min="0.000001"
                                value={data.exchange_rate}
                                onChange={(e) => setData('exchange_rate', e.target.value)}
                            />
                            {errors.exchange_rate && <p className="text-sm text-destructive">{errors.exchange_rate}</p>}
                        </div>
                    </div>
                    <div className="flex items-center gap-6">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-curr-base"
                                checked={data.is_base}
                                onCheckedChange={(checked) => setData('is_base', checked === true)}
                            />
                            <Label htmlFor="edit-curr-base" className="font-normal">Base Currency</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="edit-curr-active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked === true)}
                            />
                            <Label htmlFor="edit-curr-active" className="font-normal">Active</Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function CurrenciesIndex({ currencies }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Currencies', href: '/finance/currencies' },
    ];

    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this currency?')) {
            router.delete(`/finance/currencies/${id}`);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Currencies" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Currencies</h1>
                        <p className="text-muted-foreground">Manage currencies and exchange rates for multi-currency transactions</p>
                    </div>
                    <CreateCurrencyDialog />
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Coins className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Currencies</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Symbol</TableHead>
                                    <TableHead className="text-right">Exchange Rate (to NZD)</TableHead>
                                    <TableHead>Rate Updated</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {currencies.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No currencies defined yet. Add your first currency to enable multi-currency support.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    currencies.map((currency) => (
                                        <TableRow key={currency.id}>
                                            <TableCell className="font-mono text-sm font-semibold">
                                                {currency.code}
                                                {currency.is_base && (
                                                    <Badge variant="outline" className="ml-2 bg-blue-500/10 text-blue-600 border-blue-500/30">
                                                        Base
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="font-medium">{currency.name}</TableCell>
                                            <TableCell className="text-sm">{currency.symbol}</TableCell>
                                            <TableCell className="text-right font-mono tabular-nums text-sm">
                                                {Number(currency.exchange_rate).toFixed(6)}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDate(currency.rate_updated_at)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        currency.is_active
                                                            ? 'bg-green-500/10 text-green-600 border-green-500/30'
                                                            : 'bg-gray-500/10 text-gray-600 border-gray-500/30'
                                                    }
                                                >
                                                    {currency.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditCurrencyDialog currency={currency} />
                                                    {!currency.is_base && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => handleDelete(currency.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4 text-destructive" />
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
