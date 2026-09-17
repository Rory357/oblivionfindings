import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Currency = {
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

/**
 * Currency add/edit — a single section of seven fields, so it is a simple
 * dialog (POPUP_STYLE_GUIDE), not a WizardShell. `currency` prefills it and
 * PUTs the update.
 */
export function CurrencyDialog({
    open,
    onClose,
    currency,
}: {
    open: boolean;
    onClose: () => void;
    currency?: Currency | null;
}) {
    const isEdit = !!currency;

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({
            code: currency?.code ?? '',
            name: currency?.name ?? '',
            symbol: currency?.symbol ?? '',
            decimal_places: currency?.decimal_places ?? 2,
            exchange_rate: currency?.exchange_rate ?? '1.000000',
            is_base: currency?.is_base ?? false,
            is_active: currency?.is_active ?? true,
        });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => close() };
        if (isEdit && currency) {
            put(`/finance/currencies/${currency.id}`, options);
        } else {
            post('/finance/currencies', options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit currency' : 'New currency'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update this currency and its exchange rate to NZD.'
                            : 'Add a currency for multi-currency invoices, bills and bank accounts.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="curr-code">Code (ISO 4217)</Label>
                            <Input
                                id="curr-code"
                                value={data.code}
                                onChange={(e) =>
                                    setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="e.g. AUD"
                                maxLength={3}
                                required
                            />
                            {errors.code ? (
                                <p className="text-sm text-destructive">
                                    {errors.code}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="curr-name">Name</Label>
                            <Input
                                id="curr-name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Australian dollar"
                                required
                            />
                            {errors.name ? (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="curr-symbol">Symbol</Label>
                            <Input
                                id="curr-symbol"
                                value={data.symbol}
                                onChange={(e) =>
                                    setData('symbol', e.target.value)
                                }
                                placeholder="e.g. A$"
                                maxLength={10}
                                required
                            />
                            {errors.symbol ? (
                                <p className="text-sm text-destructive">
                                    {errors.symbol}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="curr-decimals">
                                Decimal places
                            </Label>
                            <Input
                                id="curr-decimals"
                                type="number"
                                min={0}
                                max={6}
                                value={data.decimal_places}
                                onChange={(e) =>
                                    setData(
                                        'decimal_places',
                                        parseInt(e.target.value) || 2,
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="curr-rate">Rate to NZD</Label>
                            <Input
                                id="curr-rate"
                                type="number"
                                step="0.000001"
                                min="0.000001"
                                value={data.exchange_rate}
                                onChange={(e) =>
                                    setData('exchange_rate', e.target.value)
                                }
                                required
                            />
                            {errors.exchange_rate ? (
                                <p className="text-sm text-destructive">
                                    {errors.exchange_rate}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-6">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.is_base}
                                onCheckedChange={(checked) =>
                                    setData('is_base', checked === true)
                                }
                            />
                            Base currency
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            Active
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save currency'
                                  : 'Create currency'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
