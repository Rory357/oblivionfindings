import { PageHero, PageLayout } from '@/components/page';
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
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Ban, Car, CheckCircle2, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface DriverRecord {
    id: number;
    user: { id: number; name: string };
    licence_class: string;
    licence_number: string;
    licence_expiry?: string | null;
    licence_expires_at?: string | null;
    status: 'eligible' | 'pending_review' | 'suspended' | 'expired';
    approved_at: string | null;
    suspended_at: string | null;
}

interface Employee {
    user_id: number;
    name: string;
    position_title: string | null;
}

interface Props {
    records: {
        data: DriverRecord[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    summary: {
        total: number;
        eligible: number;
        expiring: number;
        pending: number;
        suspended: number;
    };
    employees: Employee[];
    filters: { status: string | null; q: string };
    can: { manage?: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Driver Eligibility', href: '/hr/compliance/drivers' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    eligible: {
        className:
            'border-status-success/30 bg-status-success-bg text-status-success',
        label: 'Eligible',
    },
    pending_review: {
        className:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        label: 'Pending Review',
    },
    suspended: {
        className:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        label: 'Suspended',
    },
    expired: {
        className: 'border-border/30 bg-muted text-muted-foreground',
        label: 'Expired',
    },
};

const todayIso = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    user_id: '',
    licence_number: '',
    licence_class: '',
    licence_endorsements: '',
    licence_expires_at: '',
    incident_free_since: '',
    notes: '',
};

export default function DriversIndex({
    records,
    summary,
    employees,
    filters,
    can,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [suspendId, setSuspendId] = useState<number | null>(null);
    const [suspendReason, setSuspendReason] = useState('');

    const set = (key: string, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const fieldError = (field: string) =>
        errors?.[field] ? (
            <p className="mt-1 text-xs text-status-critical">{errors[field]}</p>
        ) : null;

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/compliance/drivers',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            '/hr/compliance/drivers',
            {
                ...form,
                licence_endorsements: form.licence_endorsements
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean),
                incident_free_since: form.incident_free_since || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateOpen(false);
                    setForm(emptyForm);
                },
            },
        );
    };

    const approve = (record: DriverRecord) => {
        router.post(
            `/hr/compliance/drivers/${record.id}/approve`,
            {},
            { preserveScroll: true },
        );
    };

    const submitSuspend = (e: FormEvent) => {
        e.preventDefault();
        if (suspendId === null) return;
        router.post(
            `/hr/compliance/drivers/${suspendId}/suspend`,
            { suspension_reason: suspendReason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSuspendId(null);
                    setSuspendReason('');
                },
            },
        );
    };

    const colSpan = can.manage ? 7 : 6;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Driver Eligibility" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Car}
                        title="Driver Eligibility Register"
                        description="Staff driving licence status, eligibility, and expiry tracking."
                        stats={[
                            { label: 'Total', value: summary.total },
                            { label: 'Eligible', value: summary.eligible },
                            { label: 'Expiring', value: summary.expiring },
                            { label: 'Suspended', value: summary.suspended },
                        ]}
                        actions={
                            can.manage ? (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setForm(emptyForm);
                                        setCreateOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Driver
                                </Button>
                            ) : null
                        }
                    />
                }
            >
                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">
                                {summary.total}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Eligible
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-success">
                                {summary.eligible}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Pending
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-warning">
                                {summary.pending}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Suspended
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-critical">
                                {summary.suspended}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Expiring
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-muted-foreground">
                                {summary.expiring}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search by name or licence..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')
                                applyFilter(
                                    'q',
                                    (e.target as HTMLInputElement).value,
                                );
                        }}
                    />
                    <Select
                        value={filters.status || '__none__'}
                        onValueChange={(v) =>
                            applyFilter('status', v === '__none__' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="eligible">Eligible</SelectItem>
                            <SelectItem value="pending_review">
                                Pending Review
                            </SelectItem>
                            <SelectItem value="expiring">Expiring</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Licence Class
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Licence Number
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Expiry
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Approved
                                    </th>
                                    {can.manage && (
                                        <th className="px-4 py-3 text-right font-medium">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {records.data.map((record) => {
                                    const config =
                                        statusConfig[record.status] ||
                                        statusConfig.pending_review;
                                    return (
                                        <tr
                                            key={record.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {record.user.name}
                                            </td>
                                            <td className="px-4 py-3">
                                                {record.licence_class}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {record.licence_number}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {record.licence_expires_at ||
                                                    record.licence_expiry ||
                                                    '-'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {record.approved_at || '—'}
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-3">
                                                    <div className="flex justify-end gap-2">
                                                        {record.status !==
                                                            'eligible' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    approve(
                                                                        record,
                                                                    )
                                                                }
                                                            >
                                                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                Approve
                                                            </Button>
                                                        )}
                                                        {record.status ===
                                                            'eligible' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setSuspendReason(
                                                                        '',
                                                                    );
                                                                    setSuspendId(
                                                                        record.id,
                                                                    );
                                                                }}
                                                            >
                                                                <Ban className="mr-1 h-3 w-3" />
                                                                Suspend
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                                {records.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={colSpan}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No driver eligibility records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {records.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(records.current_page - 1) * records.per_page + 1}{' '}
                            to{' '}
                            {Math.min(
                                records.current_page * records.per_page,
                                records.total,
                            )}{' '}
                            of {records.total} results
                        </p>
                        <LaravelPagination links={records.links} />
                    </div>
                )}
            </PageLayout>

            {/* Add Driver Dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add Driver Eligibility Record</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div>
                            <Label htmlFor="user_id">Staff Member</Label>
                            <Select
                                value={form.user_id}
                                onValueChange={(val) => set('user_id', val)}
                            >
                                <SelectTrigger id="user_id">
                                    <SelectValue placeholder="Select a staff member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((emp) => (
                                        <SelectItem
                                            key={emp.user_id}
                                            value={String(emp.user_id)}
                                        >
                                            {emp.name}
                                            {emp.position_title
                                                ? ` — ${emp.position_title}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fieldError('user_id')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="licence_number">
                                    Licence Number
                                </Label>
                                <Input
                                    id="licence_number"
                                    value={form.licence_number}
                                    onChange={(e) =>
                                        set('licence_number', e.target.value)
                                    }
                                    required
                                />
                                {fieldError('licence_number')}
                            </div>
                            <div>
                                <Label htmlFor="licence_class">
                                    Licence Class
                                </Label>
                                <Input
                                    id="licence_class"
                                    placeholder="e.g. Class 1"
                                    value={form.licence_class}
                                    onChange={(e) =>
                                        set('licence_class', e.target.value)
                                    }
                                    required
                                />
                                {fieldError('licence_class')}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="licence_endorsements">
                                Endorsements
                            </Label>
                            <Input
                                id="licence_endorsements"
                                placeholder="Comma-separated, e.g. P, V"
                                value={form.licence_endorsements}
                                onChange={(e) =>
                                    set('licence_endorsements', e.target.value)
                                }
                            />
                            {fieldError('licence_endorsements')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="licence_expires_at">
                                    Licence Expiry
                                </Label>
                                <Input
                                    id="licence_expires_at"
                                    type="date"
                                    min={todayIso()}
                                    value={form.licence_expires_at}
                                    onChange={(e) =>
                                        set('licence_expires_at', e.target.value)
                                    }
                                    required
                                />
                                {fieldError('licence_expires_at')}
                            </div>
                            <div>
                                <Label htmlFor="incident_free_since">
                                    Incident-free Since
                                </Label>
                                <Input
                                    id="incident_free_since"
                                    type="date"
                                    max={todayIso()}
                                    value={form.incident_free_since}
                                    onChange={(e) =>
                                        set(
                                            'incident_free_since',
                                            e.target.value,
                                        )
                                    }
                                />
                                {fieldError('incident_free_since')}
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                value={form.notes}
                                onChange={(e) => set('notes', e.target.value)}
                            />
                            {fieldError('notes')}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={!form.user_id}>
                                Add Record
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Suspend Dialog */}
            <Dialog
                open={suspendId !== null}
                onOpenChange={(o) => !o && setSuspendId(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Suspend Driving Privileges</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitSuspend} className="space-y-4">
                        <div>
                            <Label htmlFor="suspension_reason">
                                Reason for suspension
                            </Label>
                            <Textarea
                                id="suspension_reason"
                                value={suspendReason}
                                onChange={(e) =>
                                    setSuspendReason(e.target.value)
                                }
                                placeholder="Why are driving privileges being suspended?"
                                required
                            />
                            {fieldError('suspension_reason')}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSuspendId(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={!suspendReason.trim()}
                            >
                                Suspend
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
