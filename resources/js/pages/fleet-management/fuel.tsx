import { ConfirmDialog } from '@/components/confirm-dialog';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/fleet-utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface FuelLog {
    id: number;
    asset: { id: number; name: string; asset_tag: string } | null;
    user: { id: number; name: string } | null;
    logged_at: string | null;
    fuel_type: string;
    quantity_litres: number;
    cost_per_litre: number | null;
    total_cost: number;
    odometer_km: number | null;
    full_tank: boolean;
    station_name: string | null;
}

interface Props {
    logs: {
        data: FuelLog[];
        links: any[];
        meta: { current_page: number; last_page: number; total: number };
    };
    vehicles: { id: number; name: string; asset_tag: string }[];
    stats: {
        total_logs: number;
        total_litres: number;
        total_cost: number;
        avg_cost_per_litre: number;
    };
    filters: Record<string, string>;
    can: { manage: boolean };
}

export default function FleetFuelIndex({
    logs,
    vehicles,
    stats,
    filters,
    can,
}: Props) {
    const [showAddDialog, setShowAddDialog] = useState(false);
    const [selectedVehicle, setSelectedVehicle] = useState<number | null>(null);
    const [deletingFuelId, setDeletingFuelId] = useState<number | null>(null);

    const form = useForm({
        logged_at: new Date().toISOString().slice(0, 16),
        fuel_type: 'petrol',
        quantity_litres: '',
        total_cost: '',
        odometer_km: '',
        full_tank: true,
        station_name: '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedVehicle) return;
        if (
            !form.data.quantity_litres ||
            parseFloat(form.data.quantity_litres) <= 0
        ) {
            form.setError(
                'quantity_litres',
                'Quantity must be greater than 0.',
            );
            return;
        }
        if (!form.data.total_cost || parseFloat(form.data.total_cost) <= 0) {
            form.setError('total_cost', 'Cost must be greater than 0.');
            return;
        }

        form.post(`/fleet/vehicles/${selectedVehicle}/fuel`, {
            onSuccess: () => {
                setShowAddDialog(false);
                form.reset();
                setSelectedVehicle(null);
            },
        });
    };

    const handleDelete = (id: number) => {
        router.delete(`/fleet/fuel/${id}`, { preserveScroll: true });
    };

    const applyFilter = (key: string, value: string) => {
        router.get(
            '/fleet/fuel',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
                { title: 'Fuel Logs', href: '#' },
            ]}
        >
            <Head title="Fuel Logs" />
            <PageShell>
                <PageHeader
                    title="Fuel Logs"
                    description="Track fuel purchases and vehicle efficiency"
                    actions={
                        <div className="flex gap-2">
                            {can.manage && (
                                <Dialog
                                    open={showAddDialog}
                                    onOpenChange={setShowAddDialog}
                                >
                                    <DialogTrigger asChild>
                                        <Button size="sm">
                                            <Plus className="mr-2 h-4 w-4" />
                                            Add Fuel Log
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Record Fuel Purchase
                                            </DialogTitle>
                                            <DialogDescription>
                                                Log a fuel fill-up for a vehicle
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form
                                            onSubmit={handleSubmit}
                                            className="space-y-4"
                                        >
                                            <div>
                                                <Label>Vehicle</Label>
                                                <Select
                                                    value={
                                                        selectedVehicle?.toString() ||
                                                        ''
                                                    }
                                                    onValueChange={(v) =>
                                                        setSelectedVehicle(
                                                            parseInt(v),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select vehicle" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {vehicles.map((v) => (
                                                            <SelectItem
                                                                key={v.id}
                                                                value={v.id.toString()}
                                                            >
                                                                {v.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <Label>Date/Time</Label>
                                                    <Input
                                                        type="datetime-local"
                                                        value={
                                                            form.data.logged_at
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'logged_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Fuel Type</Label>
                                                    <Select
                                                        value={
                                                            form.data.fuel_type
                                                        }
                                                        onValueChange={(v) =>
                                                            form.setData(
                                                                'fuel_type',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="petrol">
                                                                Petrol
                                                            </SelectItem>
                                                            <SelectItem value="diesel">
                                                                Diesel
                                                            </SelectItem>
                                                            <SelectItem value="electric">
                                                                Electric
                                                            </SelectItem>
                                                            <SelectItem value="hybrid">
                                                                Hybrid
                                                            </SelectItem>
                                                            <SelectItem value="lpg">
                                                                LPG
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <Label>
                                                        Quantity (Litres)
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        value={
                                                            form.data
                                                                .quantity_litres
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'quantity_litres',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="0.00"
                                                    />
                                                </div>
                                                <div>
                                                    <Label>
                                                        Total Cost ($)
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        value={
                                                            form.data.total_cost
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'total_cost',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="0.00"
                                                    />
                                                </div>
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <Label>Odometer (km)</Label>
                                                    <Input
                                                        type="number"
                                                        value={
                                                            form.data
                                                                .odometer_km
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'odometer_km',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Optional"
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Station</Label>
                                                    <Input
                                                        type="text"
                                                        value={
                                                            form.data
                                                                .station_name
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'station_name',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Optional"
                                                    />
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    id="full_tank"
                                                    checked={
                                                        form.data.full_tank
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'full_tank',
                                                            e.target.checked,
                                                        )
                                                    }
                                                    className="rounded"
                                                />
                                                <Label htmlFor="full_tank">
                                                    Full tank fill-up
                                                </Label>
                                            </div>
                                            <div>
                                                <Label>Notes</Label>
                                                <Textarea
                                                    value={form.data.notes}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'notes',
                                                            e.target.value,
                                                        )
                                                    }
                                                    rows={2}
                                                />
                                            </div>
                                            <DialogFooter>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        form.processing ||
                                                        !selectedVehicle
                                                    }
                                                >
                                                    Save
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/fleet-management">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-xs text-muted-foreground">
                            Total Fill-ups
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.total_logs}
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-xs text-muted-foreground">
                            Total Litres
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {stats.total_litres.toLocaleString()}L
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-xs text-muted-foreground">
                            Total Cost
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            {formatCurrency(stats.total_cost)}
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-xs text-muted-foreground">
                            Avg $/Litre
                        </div>
                        <div className="mt-1 text-2xl font-bold">
                            ${stats.avg_cost_per_litre.toFixed(2)}
                        </div>
                    </Card>
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap gap-3">
                    <Select
                        value={filters.asset_id || 'all'}
                        onValueChange={(v) =>
                            applyFilter('asset_id', v === 'all' ? '' : v)
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Vehicles" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Vehicles</SelectItem>
                            {vehicles.map((v) => (
                                <SelectItem key={v.id} value={v.id.toString()}>
                                    {v.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Logs Table */}
                <div className="mt-4 rounded-lg border">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-2 text-left font-medium">
                                        Date
                                    </th>
                                    <th className="px-4 py-2 text-left font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-4 py-2 text-left font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Litres
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Cost
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        $/L
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Odometer
                                    </th>
                                    <th className="px-4 py-2 text-center font-medium">
                                        Full
                                    </th>
                                    {can.manage && (
                                        <th className="px-4 py-2"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.length ? (
                                    logs.data.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-2">
                                                {formatDateTime(log.logged_at)}
                                            </td>
                                            <td className="px-4 py-2">
                                                {log.asset?.name ?? '-'}
                                            </td>
                                            <td className="px-4 py-2 capitalize">
                                                {log.fuel_type}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {log.quantity_litres}L
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {formatCurrency(log.total_cost)}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {log.cost_per_litre
                                                    ? `$${log.cost_per_litre.toFixed(2)}`
                                                    : '-'}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {log.odometer_km
                                                    ? `${log.odometer_km.toLocaleString()} km`
                                                    : '-'}
                                            </td>
                                            <td className="px-4 py-2 text-center">
                                                {log.full_tank ? (
                                                    <Badge variant="secondary">
                                                        Yes
                                                    </Badge>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-2 text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDeletingFuelId(
                                                                log.id,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={can.manage ? 9 : 8}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No fuel logs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {logs.meta.last_page > 1 && (
                        <div className="flex items-center justify-center gap-2 border-t px-4 py-3">
                            {logs.links
                                .filter(
                                    (link: any) =>
                                        link.url &&
                                        !link.label.includes('Previous') &&
                                        !link.label.includes('Next'),
                                )
                                .slice(0, 10)
                                .map((link: any, i: number) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={link.url || '#'}
                                            preserveState
                                            preserveScroll
                                        >
                                            {link.label}
                                        </Link>
                                    </Button>
                                ))}
                        </div>
                    )}
                </div>
            </PageShell>

            <ConfirmDialog
                open={deletingFuelId !== null}
                onClose={() => setDeletingFuelId(null)}
                onConfirm={() => {
                    if (deletingFuelId !== null) handleDelete(deletingFuelId);
                }}
                title="Delete Fuel Log"
                description="Are you sure you want to delete this fuel log?"
                confirmText="Delete"
            />
        </AppLayout>
    );
}
