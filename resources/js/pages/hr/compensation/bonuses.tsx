import {
    CompensationHero,
    CompensationTabs,
    type CompensationHeroStats,
} from '@/components/hr';
import { StatusBadge } from '@/components/hr/status-badge';
import { PageLayout } from '@/components/page';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, DollarSign, Plus, XCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

const formatMoney = (value: number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: currency || 'NZD',
        minimumFractionDigits: 2,
    }).format(Number(value) || 0);

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
    stats: CompensationHeroStats;
    can: { manage?: boolean };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Bonuses', href: '/hr/compensation/bonuses' },
];
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

export default function BonusIndex({ bonuses, employees, stats, can }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [cancelTarget, setCancelTarget] = useState<Bonus | null>(null);

    function confirmCancelBonus() {
        const target = cancelTarget;
        if (!target) return;
        setCancelTarget(null);
        router.post(
            `/hr/compensation/bonuses/${target.id}/cancel`,
            {},
            {
                preserveScroll: true,
            },
        );
    }

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
            <Head title="Compensation & Benefits" />
            <PageLayout hero={<CompensationHero stats={stats} />}>
                <CompensationTabs active="bonuses" />
                {can.manage ? (
                    <div className="flex justify-end">
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            Record bonus
                        </Button>
                    </div>
                ) : null}
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
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Reason</TableHead>
                                        <TableHead className="text-center">
                                            Status
                                        </TableHead>
                                        {can.manage && (
                                            <TableHead className="text-right">
                                                Actions
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {bonuses.data.map((b) => (
                                        <TableRow key={b.id}>
                                            <TableCell className="font-medium">
                                                {b.employee_name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground capitalize">
                                                {BONUS_TYPES.find(
                                                    (t) =>
                                                        t.value ===
                                                        b.bonus_type,
                                                )?.label ??
                                                    b.bonus_type.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    b.amount,
                                                    b.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {b.payment_date}
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate text-muted-foreground">
                                                {b.reason || '—'}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <StatusBadge
                                                    status={b.status}
                                                />
                                            </TableCell>
                                            {can.manage && (
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-2">
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
                                                        {(b.status ===
                                                            'pending' ||
                                                            b.status ===
                                                                'approved') && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setCancelTarget(
                                                                        b,
                                                                    )
                                                                }
                                                            >
                                                                <XCircle className="mr-1 h-3 w-3" />
                                                                Cancel
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>

            <AlertDialog
                open={cancelTarget !== null}
                onOpenChange={(o) => {
                    if (!o) setCancelTarget(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Cancel this bonus?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {cancelTarget
                                ? `${cancelTarget.employee_name} · ${formatMoney(cancelTarget.amount, cancelTarget.currency)}. `
                                : ''}
                            {cancelTarget?.status === 'approved'
                                ? 'This bonus was already approved — cancelling withdraws it before payment and notifies the employee.'
                                : 'This pending bonus will be closed without the employee being notified.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep bonus</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmCancelBonus}>
                            Cancel bonus
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Record Bonus Payment</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="employee_profile_id">
                                Employee
                            </Label>
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
                                            {emp.user?.name ??
                                                `Profile #${emp.id}`}
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
                            <Button
                                type="submit"
                                disabled={!form.employee_profile_id}
                            >
                                Record Bonus
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
