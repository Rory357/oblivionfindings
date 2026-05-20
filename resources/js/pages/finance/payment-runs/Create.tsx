import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';
import { Banknote, FileText } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { useMemo } from 'react';

type BankAccount = {
    id: number;
    name: string;
    bank_name: string;
};

type Bill = {
    id: number;
    bill_number: string;
    bill_date: string;
    due_date: string;
    total_amount: number;
    amount_paid: number;
    amount_due: number;
    vendor: { id: number; name: string } | null;
};

type PageProps = {
    bankAccounts: BankAccount[];
    bills: Bill[];
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

export default function PaymentRunCreate({ bankAccounts, bills }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Payment Runs', href: '/finance/payment-runs' },
        { title: 'New Payment Run', href: '/finance/payment-runs/create' },
    ];

    const { data, setData, post, processing, errors } = useForm({
        bank_account_id: '' as string | number,
        payment_date: new Date().toISOString().slice(0, 10),
        notes: '',
        bill_ids: [] as number[],
    });

    const selectedTotal = useMemo(() => {
        return bills
            .filter((b) => data.bill_ids.includes(b.id))
            .reduce((sum, b) => sum + b.amount_due, 0);
    }, [data.bill_ids, bills]);

    const toggleBill = (billId: number) => {
        setData(
            'bill_ids',
            data.bill_ids.includes(billId)
                ? data.bill_ids.filter((id) => id !== billId)
                : [...data.bill_ids, billId],
        );
    };

    const selectAll = () => {
        setData('bill_ids', bills.map((b) => b.id));
    };

    const deselectAll = () => {
        setData('bill_ids', []);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/payment-runs');
    };

    const isOverdue = (dueDate: string) => {
        return new Date(dueDate) < new Date(new Date().toISOString().slice(0, 10));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Payment Run" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/finance/payment-runs"
                        title="New Payment Run"
                        description="Select bills to include in a batch payment"
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Banknote className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Payment Details</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="bank_account_id">Bank Account</Label>
                                    <Select
                                        value={String(data.bank_account_id)}
                                        onValueChange={(val) => setData('bank_account_id', Number(val))}
                                    >
                                        <SelectTrigger id="bank_account_id">
                                            <SelectValue placeholder="Select bank account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {bankAccounts.map((acc) => (
                                                <SelectItem key={acc.id} value={String(acc.id)}>
                                                    {acc.name} ({acc.bank_name})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.bank_account_id && (
                                        <p className="text-sm text-destructive">{errors.bank_account_id}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="payment_date">Payment Date</Label>
                                    <Input
                                        id="payment_date"
                                        type="date"
                                        value={data.payment_date}
                                        onChange={(e) => setData('payment_date', e.target.value)}
                                    />
                                    {errors.payment_date && (
                                        <p className="text-sm text-destructive">{errors.payment_date}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Optional notes for this payment run..."
                                    rows={2}
                                />
                                {errors.notes && (
                                    <p className="text-sm text-destructive">{errors.notes}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <FileText className="h-5 w-5 text-muted-foreground" />
                                    <CardTitle>Select Bills</CardTitle>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button type="button" variant="outline" size="sm" onClick={selectAll}>
                                        Select All
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={deselectAll}>
                                        Deselect All
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {errors.bill_ids && (
                                <p className="mb-4 text-sm text-destructive">{errors.bill_ids}</p>
                            )}

                            {bills.length === 0 ? (
                                <div className="py-12 text-center text-muted-foreground">
                                    No approved or partially-paid bills available for payment.
                                </div>
                            ) : (
                                <>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-12" />
                                                <TableHead>Vendor</TableHead>
                                                <TableHead>Bill #</TableHead>
                                                <TableHead>Bill Date</TableHead>
                                                <TableHead>Due Date</TableHead>
                                                <TableHead className="text-right">Amount Due</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {bills.map((bill) => (
                                                <TableRow
                                                    key={bill.id}
                                                    className="cursor-pointer"
                                                    onClick={() => toggleBill(bill.id)}
                                                >
                                                    <TableCell>
                                                        <Checkbox
                                                            checked={data.bill_ids.includes(bill.id)}
                                                            onCheckedChange={() => toggleBill(bill.id)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="font-medium">
                                                        {bill.vendor?.name || '-'}
                                                    </TableCell>
                                                    <TableCell className="font-mono">{bill.bill_number}</TableCell>
                                                    <TableCell>{bill.bill_date}</TableCell>
                                                    <TableCell>
                                                        <span className="flex items-center gap-2">
                                                            {bill.due_date}
                                                            {isOverdue(bill.due_date) && (
                                                                <Badge variant="destructive" className="text-xs">
                                                                    Overdue
                                                                </Badge>
                                                            )}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums">
                                                        {formatNZD(bill.amount_due)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>

                                    <div className="mt-4 flex items-center justify-between border-t pt-4">
                                        <span className="text-sm text-muted-foreground">
                                            {data.bill_ids.length} of {bills.length} bills selected
                                        </span>
                                        <div className="text-right">
                                            <span className="text-sm text-muted-foreground">Selected Total: </span>
                                            <span className="text-lg font-semibold font-mono tabular-nums">
                                                {formatNZD(selectedTotal)}
                                            </span>
                                        </div>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={processing || data.bill_ids.length === 0 || !data.bank_account_id}
                            size="lg"
                        >
                            Create Payment Run
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
