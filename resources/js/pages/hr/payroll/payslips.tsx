import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download, FileText, Plus, Receipt } from 'lucide-react';
import { useState } from 'react';

interface Payslip {
    id: number;
    user_id: number;
    pay_period_start: string;
    pay_period_end: string;
    gross_pay: string;
    net_pay: string;
    paye: string;
    status: 'draft' | 'approved' | 'paid';
    user?: { id: number; name: string };
    employee_profile?: {
        id: number;
        employee_number: string;
        position_title: string;
    };
}

interface Employee {
    id: number;
    name: string;
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
    employees: Employee[];
    filters: {
        status: string | null;
        user_id: string | null;
        period_start: string | null;
        period_end: string | null;
    };
    can: { generate: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Payroll', href: '/hr/payroll' },
    { title: 'Payslips', href: '/hr/payroll/payslips' },
];

const ALL_FILTER_VALUE = '__all__';

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Draft',
    },
    approved: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'Approved',
    },
    paid: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Paid',
    },
};

function formatCurrency(amount: string | number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(amount));
}

export default function PayslipsIndex({
    payslips,
    employees,
    filters,
    can,
}: Props) {
    const [showGenerate, setShowGenerate] = useState(false);
    const [generateForm, setGenerateForm] = useState({
        period_start: '',
        period_end: '',
        employee_profile_id: '',
    });

    function applyFilters(key: string, value: string) {
        router.get(
            '/hr/payroll/payslips',
            {
                ...filters,
                [key]: value && value !== ALL_FILTER_VALUE ? value : undefined,
            },
            { preserveState: true },
        );
    }

    function handleGenerate() {
        router.post('/hr/payroll/payslips/generate', generateForm, {
            preserveScroll: true,
            onSuccess: () => setShowGenerate(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payslips" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Receipt}
                        title="Payslips"
                        description="Generate, review, and distribute employee payslips."
                        stats={[
                            { label: 'Total', value: payslips.total },
                            { label: 'Drafts', value: payslips.data.filter((p) => p.status === 'draft').length },
                            { label: 'Approved', value: payslips.data.filter((p) => p.status === 'approved').length },
                            { label: 'Paid', value: payslips.data.filter((p) => p.status === 'paid').length },
                        ]}
                        actions={
                            can.generate ? (
                                <Button onClick={() => setShowGenerate(!showGenerate)}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Generate Payslips
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Generate Form */}
                {showGenerate && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Period Start</Label>
                                    <Input
                                        type="date"
                                        value={generateForm.period_start}
                                        onChange={(e) =>
                                            setGenerateForm({
                                                ...generateForm,
                                                period_start: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Period End</Label>
                                    <Input
                                        type="date"
                                        value={generateForm.period_end}
                                        onChange={(e) =>
                                            setGenerateForm({
                                                ...generateForm,
                                                period_end: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="flex items-end">
                                    <Button
                                        onClick={handleGenerate}
                                        disabled={
                                            !generateForm.period_start ||
                                            !generateForm.period_end
                                        }
                                    >
                                        Generate
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div>
                                <Label>Status</Label>
                                <Select
                                    value={filters.status ?? ALL_FILTER_VALUE}
                                    onValueChange={(v) =>
                                        applyFilters('status', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_FILTER_VALUE}>
                                            All
                                        </SelectItem>
                                        <SelectItem value="draft">
                                            Draft
                                        </SelectItem>
                                        <SelectItem value="approved">
                                            Approved
                                        </SelectItem>
                                        <SelectItem value="paid">
                                            Paid
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Employee</Label>
                                <Select
                                    value={filters.user_id ?? ALL_FILTER_VALUE}
                                    onValueChange={(v) =>
                                        applyFilters('user_id', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All employees" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_FILTER_VALUE}>
                                            All
                                        </SelectItem>
                                        {employees.map((emp) => (
                                            <SelectItem
                                                key={emp.id}
                                                value={String(emp.id)}
                                            >
                                                {emp.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>From</Label>
                                <Input
                                    type="date"
                                    value={filters.period_start ?? ''}
                                    onChange={(e) =>
                                        applyFilters(
                                            'period_start',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label>To</Label>
                                <Input
                                    type="date"
                                    value={filters.period_end ?? ''}
                                    onChange={(e) =>
                                        applyFilters(
                                            'period_end',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Employee
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Period
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Gross
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        PAYE
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Net Pay
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {payslips.data.map((payslip) => {
                                    const config =
                                        statusConfig[payslip.status] ||
                                        statusConfig.draft;
                                    return (
                                        <tr
                                            key={payslip.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {payslip.user?.name ?? '-'}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {payslip.employee_profile
                                                        ?.employee_number ?? ''}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {payslip.pay_period_start}{' '}
                                                &mdash; {payslip.pay_period_end}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(
                                                    payslip.gross_pay,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {formatCurrency(payslip.paye)}
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium">
                                                {formatCurrency(
                                                    payslip.net_pay,
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/hr/payroll/payslips/${payslip.id}`}
                                                        >
                                                            <FileText className="mr-1 h-3 w-3" />
                                                            View
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/hr/payroll/payslips/${payslip.id}/download`}
                                                        >
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
                                        <td
                                            colSpan={7}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
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
                            Showing{' '}
                            {(payslips.current_page - 1) * payslips.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                payslips.current_page * payslips.per_page,
                                payslips.total,
                            )}{' '}
                            of {payslips.total} results
                        </p>
                        <LaravelPagination links={payslips.links} />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
