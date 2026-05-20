import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';

interface Allowance {
    name: string;
    amount: number;
}

interface Deduction {
    name: string;
    amount: number;
}

interface PayslipData {
    id: number;
    pay_period_start: string;
    pay_period_end: string;
    payment_date: string | null;
    gross_pay: string;
    regular_hours: string;
    overtime_hours: string;
    paye: string;
    acc_levy: string;
    kiwisaver_employee: string;
    kiwisaver_employer: string;
    student_loan: string;
    holiday_pay: string;
    total_deductions: string;
    net_pay: string;
    allowances: Allowance[] | null;
    other_deductions: Deduction[] | null;
    tax_code: string;
    kiwisaver_rate: string;
    status: string;
    user?: { id: number; name: string; email: string };
    employee_profile?: {
        id: number;
        employee_number: string;
        position_title: string;
        tax_code: string;
        kiwisaver_rate: string;
        employment_type: string;
        pay_frequency: string;
    };
    payroll_run?: {
        id: number;
        period_start: string;
        period_end: string;
        status: string;
    } | null;
}

interface YtdData {
    gross_pay: string;
    paye: string;
    acc_levy: string;
    kiwisaver_employee: string;
    kiwisaver_employer: string;
    student_loan: string;
    holiday_pay: string;
    total_deductions: string;
    net_pay: string;
}

interface Props {
    payslip: PayslipData;
    ytd: YtdData;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
    { title: 'Payslips', href: '/hr/payroll/payslips' },
    { title: 'Detail', href: '#' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Draft',
    },
    approved: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Approved',
    },
    paid: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Paid',
    },
};

function formatCurrency(amount: string | number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(amount));
}

export default function PayslipDetail({ payslip, ytd }: Props) {
    const config = statusConfig[payslip.status] || statusConfig.draft;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payslip - ${payslip.user?.name ?? 'Employee'}`} />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/payroll/payslips"
                        title={payslip.user?.name ?? 'Employee'}
                        description={`${payslip.pay_period_start} - ${payslip.pay_period_end}`}
                        actions={
                            <>
                                <Badge variant="outline" className={config.className}>
                                    {config.label}
                                </Badge>
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/hr/payroll/payslips/${payslip.id}/download`}
                                    >
                                        <Download className="mr-1.5 h-3.5 w-3.5" />
                                        Download
                                    </Link>
                                </Button>
                            </>
                        }
                    />
                }
            >
                {/* Employee Details */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Employee Number
                            </p>
                            <p className="font-medium">
                                {payslip.employee_profile?.employee_number ??
                                    '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Position
                            </p>
                            <p className="font-medium">
                                {payslip.employee_profile?.position_title ??
                                    '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Tax Code
                            </p>
                            <p className="font-medium">{payslip.tax_code}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                KiwiSaver Rate
                            </p>
                            <p className="font-medium">
                                {payslip.kiwisaver_rate}%
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Earnings */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Earnings
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">
                                            Hours
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell>Regular Hours</TableCell>
                                        <TableCell className="text-right">
                                            {Number(
                                                payslip.regular_hours,
                                            ).toFixed(2)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            -
                                        </TableCell>
                                    </TableRow>
                                    {Number(payslip.overtime_hours) > 0 && (
                                        <TableRow>
                                            <TableCell>
                                                Overtime (x1.5)
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {Number(
                                                    payslip.overtime_hours,
                                                ).toFixed(2)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                -
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {(payslip.allowances ?? []).map((a, i) => (
                                        <TableRow key={i}>
                                            <TableCell>{a.name}</TableCell>
                                            <TableCell className="text-right">
                                                -
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(a.amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {Number(payslip.holiday_pay) > 0 && (
                                        <TableRow>
                                            <TableCell>
                                                Holiday Pay (8%)
                                            </TableCell>
                                            <TableCell className="text-right">
                                                -
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(
                                                    payslip.holiday_pay,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    <TableRow className="font-bold">
                                        <TableCell>Gross Pay</TableCell>
                                        <TableCell />
                                        <TableCell className="text-right">
                                            {formatCurrency(payslip.gross_pay)}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    {/* Deductions */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Deductions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell>PAYE</TableCell>
                                        <TableCell className="text-right text-status-critical">
                                            {formatCurrency(payslip.paye)}
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell>ACC Earner Levy</TableCell>
                                        <TableCell className="text-right text-status-critical">
                                            {formatCurrency(payslip.acc_levy)}
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell>
                                            KiwiSaver ({payslip.kiwisaver_rate}
                                            %)
                                        </TableCell>
                                        <TableCell className="text-right text-status-critical">
                                            {formatCurrency(
                                                payslip.kiwisaver_employee,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                    {Number(payslip.student_loan) > 0 && (
                                        <TableRow>
                                            <TableCell>Student Loan</TableCell>
                                            <TableCell className="text-right text-status-critical">
                                                {formatCurrency(
                                                    payslip.student_loan,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {(payslip.other_deductions ?? []).map(
                                        (d, i) => (
                                            <TableRow key={i}>
                                                <TableCell>{d.name}</TableCell>
                                                <TableCell className="text-right text-status-critical">
                                                    {formatCurrency(d.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                    <TableRow className="font-bold">
                                        <TableCell>Total Deductions</TableCell>
                                        <TableCell className="text-right text-status-critical">
                                            {formatCurrency(
                                                payslip.total_deductions,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                {/* Pay Summary */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Pay Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="text-center">
                                <p className="text-sm text-muted-foreground">
                                    Gross Pay
                                </p>
                                <p className="text-xl font-bold">
                                    {formatCurrency(payslip.gross_pay)}
                                </p>
                            </div>
                            <div className="text-center">
                                <p className="text-sm text-muted-foreground">
                                    Total Deductions
                                </p>
                                <p className="text-xl font-bold text-status-critical">
                                    {formatCurrency(payslip.total_deductions)}
                                </p>
                            </div>
                            <div className="text-center">
                                <p className="text-sm text-muted-foreground">
                                    Net Pay
                                </p>
                                <p className="text-2xl font-bold text-status-success">
                                    {formatCurrency(payslip.net_pay)}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Employer Contributions */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Employer Contributions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                KiwiSaver Employer Contribution
                            </span>
                            <span className="font-medium">
                                {formatCurrency(payslip.kiwisaver_employer)}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {/* YTD Totals */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Year-to-Date Totals
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Component</TableHead>
                                    <TableHead className="text-right">
                                        YTD Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow>
                                    <TableCell>Gross Pay</TableCell>
                                    <TableCell className="text-right font-medium">
                                        {formatCurrency(ytd.gross_pay ?? 0)}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell>PAYE</TableCell>
                                    <TableCell className="text-right text-status-critical">
                                        {formatCurrency(ytd.paye ?? 0)}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell>ACC Earner Levy</TableCell>
                                    <TableCell className="text-right text-status-critical">
                                        {formatCurrency(ytd.acc_levy ?? 0)}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell>KiwiSaver Employee</TableCell>
                                    <TableCell className="text-right text-status-critical">
                                        {formatCurrency(
                                            ytd.kiwisaver_employee ?? 0,
                                        )}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell>KiwiSaver Employer</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(
                                            ytd.kiwisaver_employer ?? 0,
                                        )}
                                    </TableCell>
                                </TableRow>
                                {Number(ytd.student_loan ?? 0) > 0 && (
                                    <TableRow>
                                        <TableCell>Student Loan</TableCell>
                                        <TableCell className="text-right text-status-critical">
                                            {formatCurrency(
                                                ytd.student_loan ?? 0,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {Number(ytd.holiday_pay ?? 0) > 0 && (
                                    <TableRow>
                                        <TableCell>Holiday Pay</TableCell>
                                        <TableCell className="text-right">
                                            {formatCurrency(
                                                ytd.holiday_pay ?? 0,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TableRow className="font-bold">
                                    <TableCell>Net Pay</TableCell>
                                    <TableCell className="text-right text-status-success">
                                        {formatCurrency(ytd.net_pay ?? 0)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
