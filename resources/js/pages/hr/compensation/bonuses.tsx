import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero } from '@/components/page';
import { CompensationTabs } from '@/components/hr';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Banknote, CheckCircle2, DollarSign, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Bonus = {
    id: number;
    employee_name: string;
    bonus_type: string;
    amount: number;
    currency: string;
    reason: string | null;
    payment_date: string;
    status: string;
};
type Employee = {
    id: number;
    user: { id: number; name: string } | null;
    position_title: string | null;
};
type Props = {
    bonuses: {
        data: Bonus[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    employees: Employee[];
    can: { manage?: boolean };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Bonuses', href: '/hr/compensation/bonuses' },
];
const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        label: 'Pending',
    },
    approved: {
        className: 'border-status-info/30 bg-status-info-bg text-status-info',
        label: 'Approved',
    },
    paid: {
        className: 'border-status-success/30 bg-status-success-bg text-status-success',
        label: 'Paid',
    },
    cancelled: {
        className: 'border-border/30 text-muted-foreground',
        label: 'Cancelled',
    },
};

const BONUS_TYPES = [
    { value: 'performance', label: 'Performance' },
    { value: 'signing', label: 'Signing' },
    { value: 'retention', label: 'Retention' },
    { value: 'spot', label: 'Spot' },
    { value: 'holiday', label: 'Holiday' },
    { value: 'other', label: 'Other' },
];

const todayIso = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    employee_profile_id: '',
    bonus_type: 'performance',
    amount: '',
    payment_date: todayIso(),
    reason: '',
};

export default function BonusIndex({ bonuses, employees, can }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);

    const set = (key: string, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const fieldError = (field: string) =>
        errors?.[field] ? (
            <p className="mt-1 text-xs text-status-critical">{errors[field]}</p>
        ) : null;

    const openCreate = () => {
        setForm(emptyForm);
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/compensation/bonuses', form, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setForm(emptyForm);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bonus Payments" />
            <PageShell>
                <PageHero category="hr"
                    icon={Banknote}
                    title="Bonus Payments"
                    description="Track and manage employee bonuses and incentives."
                    stats={[
                        { label: 'Total', value: bonuses.total },
                        {
                            label: 'Pending',
                            value: bonuses.data.filter((b) => b.status === 'pending').length,
                        },
                        {
                            label: 'Paid',
                            value: bonuses.data.filter((b) => b.status === 'paid').length,
                        },
                    ]}
                    actions={
                        can.manage ? (
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Record Bonus
                            </Button>
                        ) : null
                    }
                />
                <CompensationTabs active="bonuses" />
                <Card>
                    <CardHeader>
                        <CardTitle>All Bonuses ({bonuses.total})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {bonuses.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <DollarSign className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No bonus payments recorded.</p>
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-4"
                                        onClick={openCreate}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Record Bonus
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left">
                                            Employee
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Amount
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Date
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            Reason
                                        </th>
                                        <th className="px-4 py-3 text-center">
                                            Status
                                        </th>
                                        {can.manage && (
                                            <th className="px-4 py-3 text-right">
                                                Actions
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {bonuses.data.map((b) => {
                                        const sc =
                                            statusConfig[b.status] ||
                                            statusConfig.pending;
                                        return (
                                            <tr
                                                key={b.id}
                                                className="border-b hover:bg-muted/50"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {b.employee_name}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground capitalize">
                                                    {b.bonus_type.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium">
                                                    ${Number(b.amount).toFixed(2)}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {b.payment_date}
                                                </td>
                                                <td className="max-w-[200px] truncate px-4 py-3 text-muted-foreground">
                                                    {b.reason || '-'}
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <Badge
                                                        variant="outline"
                                                        className={sc.className}
                                                    >
                                                        {sc.label}
                                                    </Badge>
                                                </td>
                                                {can.manage && (
                                                    <td className="px-4 py-3 text-right">
                                                        {b.status ===
                                                            'pending' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/hr/compensation/bonuses/${b.id}/approve`,
                                                                    )
                                                                }
                                                            >
                                                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                Approve
                                                            </Button>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </PageShell>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Record Bonus Payment</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="employee_profile_id">Employee</Label>
                            <Select
                                value={form.employee_profile_id}
                                onValueChange={(val) =>
                                    set('employee_profile_id', val)
                                }
                            >
                                <SelectTrigger id="employee_profile_id">
                                    <SelectValue placeholder="Select an employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((emp) => (
                                        <SelectItem
                                            key={emp.id}
                                            value={String(emp.id)}
                                        >
                                            {emp.user?.name ?? `Profile #${emp.id}`}
                                            {emp.position_title
                                                ? ` — ${emp.position_title}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError('employee_profile_id')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="bonus_type">Type</Label>
                                <Select
                                    value={form.bonus_type}
                                    onValueChange={(val) =>
                                        set('bonus_type', val)
                                    }
                                >
                                    <SelectTrigger id="bonus_type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {BONUS_TYPES.map((t) => (
                                            <SelectItem
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {fieldError('bonus_type')}
                            </div>
                            <div>
                                <Label htmlFor="amount">Amount (NZD)</Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={form.amount}
                                    onChange={(e) =>
                                        set('amount', e.target.value)
                                    }
                                    required
                                />
                                {fieldError('amount')}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="payment_date">Payment Date</Label>
                            <Input
                                id="payment_date"
                                type="date"
                                value={form.payment_date}
                                onChange={(e) =>
                                    set('payment_date', e.target.value)
                                }
                                required
                            />
                            {fieldError('payment_date')}
                        </div>
                        <div>
                            <Label htmlFor="reason">Reason</Label>
                            <Textarea
                                id="reason"
                                value={form.reason}
                                onChange={(e) => set('reason', e.target.value)}
                                placeholder="Optional context for this bonus"
                            />
                            {fieldError('reason')}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={!form.employee_profile_id}>
                                Record Bonus
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
