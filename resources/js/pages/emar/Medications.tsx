import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Ban, Pencil, Pill, Plus } from 'lucide-react';
import { useState } from 'react';

type Props = {
    medications: { data: any[]; links: any };
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    filters: { search?: string; status?: string; type?: string; client_id?: string };
};

const doseUnits = ['mg', 'mcg', 'g', 'ml', 'units', 'tablets', 'capsules', 'drops', 'puffs'];
const frequencies = [
    'Once daily', 'Twice daily', 'Three times daily', 'Four times daily',
    'Every 4 hours', 'Every 6 hours', 'Every 8 hours', 'Every 12 hours',
    'Weekly', 'Fortnightly', 'Monthly', 'PRN', 'Stat',
];
const routes = [
    'oral', 'sublingual', 'topical', 'transdermal', 'inhaled', 'nebulised',
    'subcutaneous', 'intramuscular', 'intravenous', 'rectal', 'vaginal', 'optic', 'otic', 'nasal',
];
const forms = [
    'tablet', 'capsule', 'liquid', 'cream', 'ointment', 'gel', 'patch',
    'inhaler', 'injection', 'suppository', 'drops', 'spray', 'powder',
];

function defaultFormData() {
    return {
        client_id: '',
        medication_name: '',
        brand_name: '',
        dose: '',
        dose_unit: '',
        frequency: '',
        route: '',
        form: '',
        instructions: '',
        indication: '',
        is_prn: false as boolean,
        prn_reason: '',
        max_doses_per_day: '',
        min_hours_between_doses: '',
        is_controlled_drug: false as boolean,
        is_high_risk: false as boolean,
        witness_required: false as boolean,
        start_date: '',
        prescriber_name: '',
    };
}

function MedicationFormFields({
    form,
    clients,
    idPrefix,
}: {
    form: ReturnType<typeof useForm<ReturnType<typeof defaultFormData>>>;
    clients: Props['clients'];
    idPrefix: string;
}) {
    return (
        <>
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_client_id`}>Client *</Label>
                    <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                        <SelectTrigger id={`${idPrefix}_client_id`}>
                            <SelectValue placeholder="Select client" />
                        </SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>
                                    {c.last_name}, {c.first_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.client_id && <p className="text-xs text-red-600">{form.errors.client_id}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_medication_name`}>Medication Name *</Label>
                    <Input
                        id={`${idPrefix}_medication_name`}
                        value={form.data.medication_name}
                        onChange={(e) => form.setData('medication_name', e.target.value)}
                    />
                    {form.errors.medication_name && <p className="text-xs text-red-600">{form.errors.medication_name}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_brand_name`}>Brand Name</Label>
                    <Input
                        id={`${idPrefix}_brand_name`}
                        value={form.data.brand_name}
                        onChange={(e) => form.setData('brand_name', e.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_dose`}>Dose *</Label>
                    <Input
                        id={`${idPrefix}_dose`}
                        value={form.data.dose}
                        onChange={(e) => form.setData('dose', e.target.value)}
                    />
                    {form.errors.dose && <p className="text-xs text-red-600">{form.errors.dose}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_dose_unit`}>Dose Unit *</Label>
                    <Select value={form.data.dose_unit} onValueChange={(v) => form.setData('dose_unit', v)}>
                        <SelectTrigger id={`${idPrefix}_dose_unit`}>
                            <SelectValue placeholder="Select unit" />
                        </SelectTrigger>
                        <SelectContent>
                            {doseUnits.map((u) => (
                                <SelectItem key={u} value={u}>{u}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.dose_unit && <p className="text-xs text-red-600">{form.errors.dose_unit}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_frequency`}>Frequency *</Label>
                    <Select value={form.data.frequency} onValueChange={(v) => form.setData('frequency', v)}>
                        <SelectTrigger id={`${idPrefix}_frequency`}>
                            <SelectValue placeholder="Select frequency" />
                        </SelectTrigger>
                        <SelectContent>
                            {frequencies.map((f) => (
                                <SelectItem key={f} value={f}>{f}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.frequency && <p className="text-xs text-red-600">{form.errors.frequency}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_route`}>Route *</Label>
                    <Select value={form.data.route} onValueChange={(v) => form.setData('route', v)}>
                        <SelectTrigger id={`${idPrefix}_route`}>
                            <SelectValue placeholder="Select route" />
                        </SelectTrigger>
                        <SelectContent>
                            {routes.map((r) => (
                                <SelectItem key={r} value={r}>{r}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.route && <p className="text-xs text-red-600">{form.errors.route}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_form`}>Form *</Label>
                    <Select value={form.data.form} onValueChange={(v) => form.setData('form', v)}>
                        <SelectTrigger id={`${idPrefix}_form`}>
                            <SelectValue placeholder="Select form" />
                        </SelectTrigger>
                        <SelectContent>
                            {forms.map((f) => (
                                <SelectItem key={f} value={f}>{f}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.errors.form && <p className="text-xs text-red-600">{form.errors.form}</p>}
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}_instructions`}>Instructions</Label>
                <Textarea
                    id={`${idPrefix}_instructions`}
                    rows={3}
                    value={form.data.instructions}
                    onChange={(e) => form.setData('instructions', e.target.value)}
                />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_indication`}>Indication</Label>
                    <Input
                        id={`${idPrefix}_indication`}
                        value={form.data.indication}
                        onChange={(e) => form.setData('indication', e.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_prescriber_name`}>Prescriber Name</Label>
                    <Input
                        id={`${idPrefix}_prescriber_name`}
                        value={form.data.prescriber_name}
                        onChange={(e) => form.setData('prescriber_name', e.target.value)}
                    />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label htmlFor={`${idPrefix}_start_date`}>Start Date</Label>
                    <Input
                        id={`${idPrefix}_start_date`}
                        type="date"
                        value={form.data.start_date}
                        onChange={(e) => form.setData('start_date', e.target.value)}
                    />
                    {form.errors.start_date && <p className="text-xs text-red-600">{form.errors.start_date}</p>}
                </div>
            </div>

            {/* Checkbox flags */}
            <div className="grid grid-cols-2 gap-4">
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_is_prn`}
                        checked={form.data.is_prn}
                        onCheckedChange={(v) => form.setData('is_prn', v === true)}
                    />
                    <Label htmlFor={`${idPrefix}_is_prn`}>PRN (as needed)</Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_is_controlled_drug`}
                        checked={form.data.is_controlled_drug}
                        onCheckedChange={(v) => form.setData('is_controlled_drug', v === true)}
                    />
                    <Label htmlFor={`${idPrefix}_is_controlled_drug`}>Controlled Drug</Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_is_high_risk`}
                        checked={form.data.is_high_risk}
                        onCheckedChange={(v) => form.setData('is_high_risk', v === true)}
                    />
                    <Label htmlFor={`${idPrefix}_is_high_risk`}>High Risk</Label>
                </div>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`${idPrefix}_witness_required`}
                        checked={form.data.witness_required}
                        onCheckedChange={(v) => form.setData('witness_required', v === true)}
                    />
                    <Label htmlFor={`${idPrefix}_witness_required`}>Witness Required</Label>
                </div>
            </div>

            {/* PRN fields - shown when is_prn is checked */}
            {form.data.is_prn && (
                <div className="rounded-md border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                    <p className="mb-3 text-sm font-medium text-blue-700 dark:text-blue-400">PRN Details</p>
                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_prn_reason`}>PRN Reason</Label>
                            <Input
                                id={`${idPrefix}_prn_reason`}
                                value={form.data.prn_reason}
                                onChange={(e) => form.setData('prn_reason', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_max_doses`}>Max Doses / Day</Label>
                            <Input
                                id={`${idPrefix}_max_doses`}
                                type="number"
                                value={form.data.max_doses_per_day}
                                onChange={(e) => form.setData('max_doses_per_day', e.target.value)}
                            />
                            {form.errors.max_doses_per_day && <p className="text-xs text-red-600">{form.errors.max_doses_per_day}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`${idPrefix}_min_hours`}>Min Hours Between</Label>
                            <Input
                                id={`${idPrefix}_min_hours`}
                                type="number"
                                value={form.data.min_hours_between_doses}
                                onChange={(e) => form.setData('min_hours_between_doses', e.target.value)}
                            />
                            {form.errors.min_hours_between_doses && <p className="text-xs text-red-600">{form.errors.min_hours_between_doses}</p>}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function AddMedicationDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm(defaultFormData());

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/medications', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1 h-4 w-4" /> Add Medication
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Add Medication</DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <MedicationFormFields form={form} clients={clients} idPrefix="add" />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Add Medication'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditMedicationDialog({ med, clients }: { med: any; clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        client_id: med.client_id?.toString() ?? '',
        medication_name: med.name ?? '',
        brand_name: med.brand_name ?? '',
        dose: med.dose_amount ?? med.dose ?? '',
        dose_unit: med.dose_unit ?? '',
        frequency: med.frequency ?? '',
        route: med.route ?? '',
        form: med.form ?? '',
        instructions: med.instructions ?? '',
        indication: med.indication ?? '',
        is_prn: !!med.is_prn,
        prn_reason: med.prn_reason ?? '',
        max_doses_per_day: med.max_doses_per_day?.toString() ?? '',
        min_hours_between_doses: med.min_hours_between_doses?.toString() ?? '',
        is_controlled_drug: !!med.controlled_drug,
        is_high_risk: !!med.high_risk,
        witness_required: !!med.witness_required,
        start_date: med.start_date ?? '',
        prescriber_name: med.prescriber_name ?? '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/emar/medications/${med.id}`, {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost" className="h-7 w-7 p-0">
                    <Pencil className="h-3.5 w-3.5" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit Medication</DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <MedicationFormFields form={form} clients={clients} idPrefix={`edit_${med.id}`} />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Update Medication'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Medications({ medications, clients, staff, filters }: Props) {
    function updateFilter(key: string, value: string) {
        router.get('/emar/medications', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    function handleDiscontinue(med: any) {
        const reason = prompt('Reason for discontinuation:');
        if (reason !== null) {
            router.post(`/emar/medications/${med.id}/discontinue`, { reason });
        }
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medications" />
            <PageHeader title="Medications Database" description="Central medication directory with search, filtering, and status tracking." backHref="/emar" />
            <PageShell>
                {/* Filters */}
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Input placeholder="Search medications..." value={filters.search ?? ''} onChange={(e) => updateFilter('search', e.target.value)} className="w-64" />
                    <Select value={filters.status ?? ''} onValueChange={(v) => updateFilter('status', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="ceased">Ceased</SelectItem>
                            <SelectItem value="paused">Paused</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.type ?? ''} onValueChange={(v) => updateFilter('type', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All types" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="prn">PRN Only</SelectItem>
                            <SelectItem value="controlled">Controlled</SelectItem>
                            <SelectItem value="high_risk">High Risk</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.client_id ?? ''} onValueChange={(v) => updateFilter('client_id', v)}>
                        <SelectTrigger className="w-56"><SelectValue placeholder="All clients" /></SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-auto">
                        <AddMedicationDialog clients={clients} />
                    </div>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Medication</th>
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Dose</th>
                                    <th className="p-3 text-left font-medium">Frequency</th>
                                    <th className="p-3 text-left font-medium">Route</th>
                                    <th className="p-3 text-left font-medium">Flags</th>
                                    <th className="p-3 text-left font-medium">State</th>
                                    <th className="p-3 text-left font-medium">Stock</th>
                                    <th className="p-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {medications.data.map((m: any) => (
                                    <tr key={m.id} className="border-b last:border-0">
                                        <td className="p-3">
                                            <span className="font-medium">{m.name}</span>
                                            {m.instructions && <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">{m.instructions}</p>}
                                        </td>
                                        <td className="p-3">{m.client?.last_name}, {m.client?.first_name}</td>
                                        <td className="p-3 text-xs">{m.dosage ?? `${m.dose_amount} ${m.dose_unit}`}</td>
                                        <td className="p-3 text-xs">{m.frequency}</td>
                                        <td className="p-3 text-xs">{m.route ?? '—'}</td>
                                        <td className="p-3">
                                            <div className="flex gap-1">
                                                {m.is_prn && <Badge variant="outline" className="text-[10px]">PRN</Badge>}
                                                {m.controlled_drug && <Badge variant="destructive" className="text-[10px]">CD</Badge>}
                                                {m.high_risk && <Badge className="bg-amber-100 text-amber-700 text-[10px]">HR</Badge>}
                                                {m.witness_required && <Badge variant="secondary" className="text-[10px]">W</Badge>}
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={m.state === 'active' ? 'default' : m.state === 'paused' ? 'secondary' : 'outline'} className="text-xs">
                                                {m.state}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-xs font-mono">{m.stock?.on_hand ?? '—'}</td>
                                        <td className="p-3 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <EditMedicationDialog med={m} clients={clients} />
                                                {m.state === 'active' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 px-2 text-xs text-red-600 hover:text-red-700"
                                                        onClick={() => handleDiscontinue(m)}
                                                    >
                                                        <Ban className="mr-1 h-3 w-3" /> Discontinue
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {medications.data.length === 0 && (
                                    <tr><td colSpan={9} className="p-6 text-center text-muted-foreground">No medications found.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
