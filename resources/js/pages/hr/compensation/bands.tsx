import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Plus, Pencil } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

type BreadcrumbItem = { title: string; href: string };

type SalaryBand = {
    id: number;
    position_role: string;
    band_name: string;
    min_salary: string;
    mid_salary: string;
    max_salary: string;
    min_hourly: string;
    max_hourly: string;
    currency: string;
    effective_from: string;
    effective_to: string | null;
};

type Props = {
    bands: { data: SalaryBand[]; links: any[] };
    filters: { role: string | null; active_only: boolean };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Salary Bands', href: '/hr/compensation/bands' },
];

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatCurrency = (value: string | null, currency = 'NZD') => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(num);
};

const emptyForm = {
    position_role: '',
    band_name: '',
    min_salary: '',
    mid_salary: '',
    max_salary: '',
    min_hourly: '',
    max_hourly: '',
    currency: 'NZD',
    effective_from: '',
    effective_to: '',
};

export default function SalaryBands({ bands, filters, can }: Props) {
    const [open, setOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const [form, setForm] = useState(emptyForm);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/compensation/bands', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const openCreate = () => {
        setEditId(null);
        setForm(emptyForm);
        setOpen(true);
    };

    const openEdit = (band: SalaryBand) => {
        setEditId(band.id);
        setForm({
            position_role: band.position_role,
            band_name: band.band_name,
            min_salary: band.min_salary,
            mid_salary: band.mid_salary,
            max_salary: band.max_salary,
            min_hourly: band.min_hourly,
            max_hourly: band.max_hourly,
            currency: band.currency,
            effective_from: band.effective_from,
            effective_to: band.effective_to ?? '',
        });
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) {
            router.put(`/hr/compensation/bands/${editId}`, form, {
                onSuccess: () => setOpen(false),
            });
        } else {
            router.post('/hr/compensation/bands', form, {
                onSuccess: () => setOpen(false),
            });
        }
    };

    const set = (key: string, value: string) => setForm((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Salary Bands" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Salary Bands</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage salary bands by position role
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Band
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Position Role</Label>
                            <Input
                                placeholder="Filter by role..."
                                value={filters.role || ''}
                                onChange={(e) => onFilter({ role: e.target.value })}
                            />
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={filters.active_only}
                                    onChange={(e) => onFilter({ active_only: e.target.checked })}
                                    className="rounded border-slate-300"
                                />
                                Active bands only
                            </label>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Position Role</TableHead>
                                    <TableHead>Band</TableHead>
                                    <TableHead>Salary Range</TableHead>
                                    <TableHead>Hourly Range</TableHead>
                                    <TableHead>Effective</TableHead>
                                    <TableHead className="w-16"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {bands.data.map((band) => (
                                    <TableRow key={band.id}>
                                        <TableCell className="font-medium">{band.position_role}</TableCell>
                                        <TableCell>{band.band_name}</TableCell>
                                        <TableCell className="text-sm text-slate-600">
                                            {formatCurrency(band.min_salary, band.currency)} - {formatCurrency(band.max_salary, band.currency)}
                                        </TableCell>
                                        <TableCell className="text-sm text-slate-600">
                                            {formatCurrency(band.min_hourly, band.currency)} - {formatCurrency(band.max_hourly, band.currency)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {formatDate(band.effective_from)}
                                            {band.effective_to ? ` - ${formatDate(band.effective_to)}` : ''}
                                        </TableCell>
                                        <TableCell>
                                            {can.manage && (
                                                <button
                                                    onClick={() => openEdit(band)}
                                                    className="rounded-md border p-1.5 text-xs hover:bg-muted"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!bands.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-sm text-slate-500">
                                            No salary bands found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {bands?.links?.length ? (
                    <LaravelPagination links={bands.links} />
                ) : null}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editId ? 'Edit Salary Band' : 'New Salary Band'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Position Role</Label>
                                <Input value={form.position_role} onChange={(e) => set('position_role', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Band Name</Label>
                                <Input value={form.band_name} onChange={(e) => set('band_name', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label>Min Salary</Label>
                                <Input type="number" step="0.01" value={form.min_salary} onChange={(e) => set('min_salary', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Mid Salary</Label>
                                <Input type="number" step="0.01" value={form.mid_salary} onChange={(e) => set('mid_salary', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Max Salary</Label>
                                <Input type="number" step="0.01" value={form.max_salary} onChange={(e) => set('max_salary', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Min Hourly</Label>
                                <Input type="number" step="0.01" value={form.min_hourly} onChange={(e) => set('min_hourly', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Max Hourly</Label>
                                <Input type="number" step="0.01" value={form.max_hourly} onChange={(e) => set('max_hourly', e.target.value)} required />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label>Currency</Label>
                                <Input value={form.currency} onChange={(e) => set('currency', e.target.value)} maxLength={3} />
                            </div>
                            <div>
                                <Label>Effective From</Label>
                                <Input type="date" value={form.effective_from} onChange={(e) => set('effective_from', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Effective To</Label>
                                <Input type="date" value={form.effective_to} onChange={(e) => set('effective_to', e.target.value)} />
                            </div>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit">{editId ? 'Update' : 'Create'}</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
