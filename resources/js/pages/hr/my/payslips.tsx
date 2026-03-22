import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { FileText, Download } from 'lucide-react';

interface Payslip {
    id: number;
    pay_period_start: string;
    pay_period_end: string;
    gross_pay: string;
    net_pay: string;
    paye: string;
    acc_levy: string;
    kiwisaver_employee: string;
    student_loan: string;
    holiday_pay: string;
    total_deductions: string;
    status: 'draft' | 'approved' | 'paid';
    payment_date: string | null;
}

interface Props {
    payslips: {
        data: Payslip[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Payslips', href: '/hr/my/payslips' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Draft' },
    approved: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Approved' },
    paid: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Paid' },
};

function formatCurrency(amount: string | number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));
}

export default function MyPayslips({ payslips }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Payslips" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Payslips</h1>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Pay Period</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Gross Pay</th>
                                    <th className="px-4 py-3 text-right font-medium">PAYE</th>
                                    <th className="px-4 py-3 text-right font-medium">KiwiSaver</th>
                                    <th className="px-4 py-3 text-right font-medium">Net Pay</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {payslips.data.map((payslip) => {
                                    const config = statusConfig[payslip.status] || statusConfig.draft;
                                    return (
                                        <tr key={payslip.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {payslip.pay_period_start} &mdash; {payslip.pay_period_end}
                                                </div>
                                                {payslip.payment_date && (
                                                    <div className="text-xs text-muted-foreground">
                                                        Paid: {payslip.payment_date}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(payslip.gross_pay)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {formatCurrency(payslip.paye)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {formatCurrency(payslip.kiwisaver_employee)}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-emerald-400">
                                                {formatCurrency(payslip.net_pay)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/hr/payroll/payslips/${payslip.id}`}>
                                                            <FileText className="mr-1 h-3 w-3" />
                                                            View
                                                        </Link>
                                                    </Button>
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={`/hr/payroll/payslips/${payslip.id}/download`}>
                                                            <Download className="mr-1 h-3 w-3" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {payslips.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                                            No payslips found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {payslips.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(payslips.current_page - 1) * payslips.per_page + 1} to{' '}
                            {Math.min(payslips.current_page * payslips.per_page, payslips.total)} of{' '}
                            {payslips.total} results
                        </p>
                        <div className="flex items-center gap-1">
                            {payslips.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
