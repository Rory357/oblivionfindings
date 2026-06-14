import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { LedgerTabsFooter } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { CalendarDays, Plus, Lock, Pencil, CalendarRange } from 'lucide-react';
import { FormEvent, useState } from 'react';

type FiscalPeriod = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'closed' | 'locked';
    closed_at: string | null;
    closed_by: string | null;
};

type PageProps = {
    periods: FiscalPeriod[];
};

const statusColors: Record<string, string> = {
    open: 'bg-status-success-bg text-status-success border-status-success/30',
    closed: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    locked: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

function CreatePeriodDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        start_date: '',
        end_date: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/fiscal-periods', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    New Period
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Fiscal Period</DialogTitle>
                    <DialogDescription>Add a new fiscal period for your organisation.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="period-name">Period Name *</Label>
                        <Input
                            id="period-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. FY 2025-26 Q1"
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="period-start">Start Date *</Label>
                            <Input
                                id="period-start"
                                type="date"
                                value={data.start_date}
                                onChange={(e) => setData('start_date', e.target.value)}
                            />
                            {errors.start_date && <p className="text-sm text-destructive">{errors.start_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="period-end">End Date *</Label>
                            <Input
                                id="period-end"
                                type="date"
                                value={data.end_date}
                                onChange={(e) => setData('end_date', e.target.value)}
                            />
                            {errors.end_date && <p className="text-sm text-destructive">{errors.end_date}</p>}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Period'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditPeriodDialog({ period }: { period: FiscalPeriod }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        name: period.name,
        start_date: period.start_date,
        end_date: period.end_date,
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/fiscal-periods/${period.id}`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" disabled={period.status !== 'open'}>
                    <Pencil className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Fiscal Period</DialogTitle>
                    <DialogDescription>Update the fiscal period details.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-name">Period Name *</Label>
                        <Input
                            id="edit-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-start">Start Date *</Label>
                            <Input
                                id="edit-start"
                                type="date"
                                value={data.start_date}
                                onChange={(e) => setData('start_date', e.target.value)}
                            />
                            {errors.start_date && <p className="text-sm text-destructive">{errors.start_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-end">End Date *</Label>
                            <Input
                                id="edit-end"
                                type="date"
                                value={data.end_date}
                                onChange={(e) => setData('end_date', e.target.value)}
                            />
                            {errors.end_date && <p className="text-sm text-destructive">{errors.end_date}</p>}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function FiscalPeriodsIndex({ periods }: PageProps) {
    const [closingId, setClosingId] = useState<number | null>(null);

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Fiscal Periods', href: '/finance/fiscal-periods' },
    ];

    function handleClose(periodId: number) {
        setClosingId(periodId);
        router.post(`/finance/fiscal-periods/${periodId}/close`, {}, {
            onFinish: () => setClosingId(null),
        });
    }

    const openCount = periods.filter((p) => p.status === 'open').length;
    const closedCount = periods.filter((p) => p.status === 'closed').length;
    const lockedCount = periods.filter((p) => p.status === 'locked').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal Periods" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        footer={<LedgerTabsFooter active="fiscal-periods" />}
                        icon={CalendarRange}
                        title="Fiscal Periods"
                        description="Manage accounting periods for your organisation"
                        stats={[
                            { label: 'Total', value: periods.length },
                            { label: 'Open', value: openCount },
                            { label: 'Closed', value: closedCount },
                            { label: 'Locked', value: lockedCount },
                        ]}
                        actions={<CreatePeriodDialog />}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <CalendarDays className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Periods</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Period Name</TableHead>
                                    <TableHead>Start Date</TableHead>
                                    <TableHead>End Date</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Closed By</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {periods.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                            No fiscal periods defined yet. Create your first period to get started.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    periods.map((period) => (
                                        <TableRow key={period.id}>
                                            <TableCell className="font-medium">{period.name}</TableCell>
                                            <TableCell>{period.start_date}</TableCell>
                                            <TableCell>{period.end_date}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={statusColors[period.status]}>
                                                    {period.status.charAt(0).toUpperCase() + period.status.slice(1)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {period.closed_by || '-'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditPeriodDialog period={period} />
                                                    {period.status === 'open' && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => handleClose(period.id)}
                                                            disabled={closingId === period.id}
                                                        >
                                                            <Lock className="mr-1 h-3 w-3" />
                                                            {closingId === period.id ? 'Closing...' : 'Close'}
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
