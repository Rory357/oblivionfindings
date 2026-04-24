import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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
import { Badge } from '@/components/ui/badge';
import { DollarSign, Clock, FileText, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

type InvoiceRow = {
    id: number;
    invoice_number: string;
    client_name: string;
    issue_date: string;
    due_date: string;
    total_amount: number;
    amount_paid: number;
    amount_due: number;
    is_overdue: boolean;
    days_overdue: number;
};

type Summary = {
    total_outstanding: number;
    total_overdue: number;
    unpaid_count: number;
};

type PageProps = {
    summary: Summary;
    invoices: InvoiceRow[];
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Receivables', href: '/finance/receivables' },
];

function PaymentDialog({ invoice, onClose }: { invoice: InvoiceRow; onClose: () => void }) {
    const form = useForm({
        invoice_id: invoice.id,
        amount: invoice.amount_due.toFixed(2),
        payment_date: new Date().toISOString().split('T')[0],
        notes: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/finance/receivables/allocate', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1">
                <Label>Invoice</Label>
                <p className="text-sm text-muted-foreground">
                    {invoice.invoice_number} — {invoice.client_name} — Outstanding: {formatNZD(invoice.amount_due)}
                </p>
            </div>

            <div className="space-y-1">
                <Label htmlFor="amount">Amount (NZD)</Label>
                <Input
                    id="amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    max={invoice.amount_due}
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                />
                {form.errors.amount && (
                    <p className="text-sm text-destructive">{form.errors.amount}</p>
                )}
            </div>

            <div className="space-y-1">
                <Label htmlFor="payment_date">Payment Date</Label>
                <Input
                    id="payment_date"
                    type="date"
                    value={form.data.payment_date}
                    onChange={(e) => form.setData('payment_date', e.target.value)}
                />
                {form.errors.payment_date && (
                    <p className="text-sm text-destructive">{form.errors.payment_date}</p>
                )}
            </div>

            <div className="space-y-1">
                <Label htmlFor="notes">Notes</Label>
                <Textarea
                    id="notes"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Optional payment reference or notes"
                    rows={2}
                />
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Processing...' : 'Record Payment'}
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function ReceivablesIndex({ summary, invoices }: PageProps) {
    const [paymentInvoice, setPaymentInvoice] = useState<InvoiceRow | null>(null);

    const currentNotOverdue = summary.total_outstanding - summary.total_overdue;
    const pieData = [
        { name: 'Outstanding (Current)', value: currentNotOverdue > 0 ? currentNotOverdue : 0 },
        { name: 'Overdue', value: summary.total_overdue },
    ].filter((d) => d.value > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Receivables" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Receivables</h1>
                        <p className="text-muted-foreground">
                            Accounts receivable dashboard for outstanding invoices and payments.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/finance/receivables/aging">
                            <Button variant="outline">
                                <TrendingUp className="mr-2 h-4 w-4" />
                                Aging Report
                            </Button>
                        </Link>
                        <Link href="/finance/receivables/statements">
                            <Button variant="outline">
                                <FileText className="mr-2 h-4 w-4" />
                                Statements
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Summary Cards + PieChart */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total Outstanding
                            </CardTitle>
                            <DollarSign className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {formatNZD(summary.total_outstanding)}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total Overdue
                            </CardTitle>
                            <Clock className="h-4 w-4 text-destructive" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-destructive">
                                {formatNZD(summary.total_overdue)}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Unpaid Invoices
                            </CardTitle>
                            <FileText className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{summary.unpaid_count}</div>
                        </CardContent>
                    </Card>

                    {/* Outstanding vs Overdue Pie Chart */}
                    {pieData.length > 0 && (
                        <Card>
                            <CardHeader className="pb-0">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Outstanding vs Overdue
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pb-4">
                                <ResponsiveContainer width="100%" height={120}>
                                    <PieChart>
                                        <Pie
                                            data={pieData}
                                            cx="50%"
                                            cy="50%"
                                            innerRadius={30}
                                            outerRadius={50}
                                            paddingAngle={3}
                                            dataKey="value"
                                            nameKey="name"
                                        >
                                            {pieData.map((_, index) => (
                                                <Cell key={`cell-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                                            ))}
                                        </Pie>
                                        <Tooltip formatter={((value: number) => formatNZD(value)) as any} />
                                    </PieChart>
                                </ResponsiveContainer>
                                <div className="flex justify-center gap-3 text-xs">
                                    {pieData.map((entry, index) => (
                                        <div key={entry.name} className="flex items-center gap-1">
                                            <div
                                                className="h-2 w-2 rounded-full"
                                                style={{ backgroundColor: CHART_COLORS[index % CHART_COLORS.length] }}
                                            />
                                            <span className="text-muted-foreground">{entry.name}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Outstanding Invoices Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Outstanding Invoices</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No outstanding invoices.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Invoice #</TableHead>
                                        <TableHead>Client</TableHead>
                                        <TableHead>Issue Date</TableHead>
                                        <TableHead>Due Date</TableHead>
                                        <TableHead className="text-right">Total</TableHead>
                                        <TableHead className="text-right">Paid</TableHead>
                                        <TableHead className="text-right">Due</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((invoice) => (
                                        <TableRow
                                            key={invoice.id}
                                            className={
                                                invoice.is_overdue
                                                    ? invoice.days_overdue > 60
                                                        ? 'bg-status-critical-bg dark:bg-status-critical'
                                                        : 'bg-status-warning-bg dark:bg-status-warning'
                                                    : ''
                                            }
                                        >
                                            <TableCell className="font-medium">
                                                {invoice.invoice_number}
                                            </TableCell>
                                            <TableCell>{invoice.client_name}</TableCell>
                                            <TableCell>{invoice.issue_date}</TableCell>
                                            <TableCell>{invoice.due_date}</TableCell>
                                            <TableCell className="text-right">
                                                {formatNZD(invoice.total_amount)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatNZD(invoice.amount_paid)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatNZD(invoice.amount_due)}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.is_overdue ? (
                                                    <Badge variant="destructive">
                                                        {invoice.days_overdue}d overdue
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">Current</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Dialog
                                                    open={paymentInvoice?.id === invoice.id}
                                                    onOpenChange={(open) => {
                                                        if (!open) setPaymentInvoice(null);
                                                    }}
                                                >
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setPaymentInvoice(invoice)
                                                            }
                                                        >
                                                            Record Payment
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogHeader>
                                                            <DialogTitle>Record Payment</DialogTitle>
                                                            <DialogDescription>
                                                                Allocate a payment against this invoice.
                                                            </DialogDescription>
                                                        </DialogHeader>
                                                        {paymentInvoice?.id === invoice.id && (
                                                            <PaymentDialog
                                                                invoice={invoice}
                                                                onClose={() =>
                                                                    setPaymentInvoice(null)
                                                                }
                                                            />
                                                        )}
                                                    </DialogContent>
                                                </Dialog>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
