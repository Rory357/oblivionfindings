import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeftRight, TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { router } from '@inertiajs/react';

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
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'FX Revaluations', href: '/finance/fx-revaluations' },
    { title: 'New Revaluation', href: '/finance/fx-revaluations/create' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatRate = (rate: number) => rate.toFixed(6);

const typeLabels: Record<string, string> = {
    bill: 'Bill',
    bank_account: 'Bank Account',
};

export default function FxRevaluationCreate({ preview, date }: PageProps) {
    const [revalDate, setRevalDate] = useState(date);

    const { data, setData, post, processing, errors } = useForm({
        date: date,
        notes: '',
    });

    function handleDateChange(newDate: string) {
        setRevalDate(newDate);
        setData('date', newDate);
        router.get('/finance/fx-revaluations/create', { date: newDate }, { preserveState: true });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/finance/fx-revaluations');
    }

    const totalGainLoss = preview.total_gain_loss;
    const isGain = totalGainLoss > 0;
    const isLoss = totalGainLoss < 0;
    const hasItems = preview.items.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New FX Revaluation" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">New FX Revaluation</h1>
                    <p className="text-muted-foreground">
                        Preview unrealised foreign exchange gain/loss and create a revaluation record
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Revaluation Date</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-end gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="reval-date">As at Date</Label>
                                <Input
                                    id="reval-date"
                                    type="date"
                                    value={revalDate}
                                    onChange={(e) => handleDateChange(e.target.value)}
                                    className="w-48"
                                />
                                {errors.date && <p className="text-sm text-destructive">{errors.date}</p>}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Summary */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                {isGain ? (
                                    <TrendingUp className="h-5 w-5 text-green-600" />
                                ) : isLoss ? (
                                    <TrendingDown className="h-5 w-5 text-red-600" />
                                ) : (
                                    <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                                )}
                                <CardTitle>
                                    Unrealised FX {isGain ? 'Gain' : isLoss ? 'Loss' : 'Gain/Loss'}
                                </CardTitle>
                            </div>
                            <div
                                className={`text-2xl font-bold font-mono tabular-nums ${
                                    isGain ? 'text-green-600' : isLoss ? 'text-red-600' : 'text-foreground'
                                }`}
                            >
                                {isLoss ? '(' : ''}
                                {formatCurrency(Math.abs(totalGainLoss))}
                                {isLoss ? ')' : ''}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {!hasItems ? (
                            <p className="text-center text-muted-foreground py-4">
                                No foreign-currency items found for this date. There are no open foreign-currency bills
                                or bank account balances to revalue.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-3 pr-4 font-medium">Type</th>
                                            <th className="pb-3 pr-4 font-medium">Reference</th>
                                            <th className="pb-3 pr-4 font-medium">Currency</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Foreign Amount</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Booked Rate</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Current Rate</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Booked NZD</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Current NZD</th>
                                            <th className="pb-3 font-medium text-right">Gain / Loss</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.items.map((item, i) => {
                                            const itemGain = item.gain_loss > 0;
                                            const itemLoss = item.gain_loss < 0;

                                            return (
                                                <tr key={i} className="border-b last:border-0">
                                                    <td className="py-3 pr-4">
                                                        {typeLabels[item.type] ?? item.type}
                                                    </td>
                                                    <td className="py-3 pr-4 font-medium">{item.reference}</td>
                                                    <td className="py-3 pr-4 font-mono">{item.currency_code}</td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {item.foreign_amount.toFixed(2)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums text-muted-foreground">
                                                        {formatRate(item.booked_rate)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums text-muted-foreground">
                                                        {formatRate(item.current_rate)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatCurrency(item.booked_base_value)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                        {formatCurrency(item.current_base_value)}
                                                    </td>
                                                    <td
                                                        className={`py-3 text-right font-mono font-semibold tabular-nums ${
                                                            itemGain
                                                                ? 'text-green-600'
                                                                : itemLoss
                                                                  ? 'text-red-600'
                                                                  : ''
                                                        }`}
                                                    >
                                                        {itemLoss ? '(' : ''}
                                                        {formatCurrency(Math.abs(item.gain_loss))}
                                                        {itemLoss ? ')' : ''}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {hasItems && (
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <Card>
                            <CardContent className="pt-6">
                                <div className="space-y-2">
                                    <Label htmlFor="reval-notes">Notes (optional)</Label>
                                    <Input
                                        id="reval-notes"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        placeholder="Optional notes about this revaluation..."
                                    />
                                </div>
                            </CardContent>
                        </Card>
                        <div className="flex justify-end">
                            <Button type="submit" disabled={processing} size="lg">
                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                                {processing ? 'Creating...' : 'Create Draft Revaluation'}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
