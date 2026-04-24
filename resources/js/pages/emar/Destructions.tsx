import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Props = {
    destructions: { data: any[]; links: any };
    filters: { controlled_only?: boolean };
    staff: { id: number; name: string }[];
    clients: { id: number; first_name: string; last_name: string }[];
    medications: { id: number; name: string; client_id: number }[];
};

const REASONS = [
    { value: 'expired', label: 'Expired' },
    { value: 'ceased', label: 'Ceased' },
    { value: 'contaminated', label: 'Contaminated' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'deceased', label: 'Deceased' },
    { value: 'discharged', label: 'Discharged' },
    { value: 'surplus', label: 'Surplus' },
];

const DISPOSAL_METHODS = [
    { value: 'pharmacy_return', label: 'Pharmacy Return' },
    { value: 'incineration', label: 'Incineration' },
    { value: 'denaturing', label: 'Denaturing' },
    { value: 'sharps_bin', label: 'Sharps Bin' },
    { value: 'other', label: 'Other' },
];

export default function Destructions({ destructions, filters, staff, clients, medications }: Props) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        client_id: '',
        medication_name: '',
        form: '',
        strength: '',
        quantity: 1,
        unit: '',
        batch_number: '',
        expiry_date: '',
        reason: '',
        disposal_method: '',
        is_controlled_drug: false,
        controlled_drug_class: '',
        authorised_by_name: '',
        authorised_by_registration: '',
        witness_1_id: '',
        witness_2_id: '',
        notes: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/destructions', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Destruction Records" />
            <PageHeader title="Medication Destruction / Disposal" description="Records of medication destruction and disposal with dual-witness verification." backHref="/emar" />
            <PageShell>
                {/* Actions */}
                <div className="mb-4 flex justify-end">
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm"><Plus className="mr-1 h-4 w-4" /> Record Destruction</Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Record Medication Destruction</DialogTitle>
                                <DialogDescription>
                                    Capture disposal details, witnesses, and
                                    controlled-drug authorisation where
                                    required.
                                </DialogDescription>
                            </DialogHeader>
                            <form onSubmit={submit} className="space-y-4">
                                {/* Client */}
                                <div>
                                    <Label>Client</Label>
                                    <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.client_id && <p className="mt-1 text-xs text-status-critical">{form.errors.client_id}</p>}
                                </div>

                                {/* Medication Details */}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="dest-med">Medication Name</Label>
                                        <Input id="dest-med" value={form.data.medication_name} onChange={(e) => form.setData('medication_name', e.target.value)} />
                                        {form.errors.medication_name && <p className="mt-1 text-xs text-status-critical">{form.errors.medication_name}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="dest-form">Form</Label>
                                        <Input id="dest-form" value={form.data.form} onChange={(e) => form.setData('form', e.target.value)} placeholder="tablet, liquid, patch..." />
                                        {form.errors.form && <p className="mt-1 text-xs text-status-critical">{form.errors.form}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="dest-strength">Strength</Label>
                                        <Input id="dest-strength" value={form.data.strength} onChange={(e) => form.setData('strength', e.target.value)} placeholder="e.g. 500mg" />
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <Label htmlFor="dest-qty">Quantity</Label>
                                            <Input id="dest-qty" type="number" min={1} value={form.data.quantity} onChange={(e) => form.setData('quantity', parseInt(e.target.value) || 1)} />
                                            {form.errors.quantity && <p className="mt-1 text-xs text-status-critical">{form.errors.quantity}</p>}
                                        </div>
                                        <div>
                                            <Label htmlFor="dest-unit">Unit</Label>
                                            <Input id="dest-unit" value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="tablets, mL..." />
                                        </div>
                                    </div>
                                    <div>
                                        <Label htmlFor="dest-batch">Batch Number</Label>
                                        <Input id="dest-batch" value={form.data.batch_number} onChange={(e) => form.setData('batch_number', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="dest-expiry">Expiry Date</Label>
                                        <Input id="dest-expiry" type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                                    </div>
                                </div>

                                {/* Reason & Method */}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Reason</Label>
                                        <Select value={form.data.reason} onValueChange={(v) => form.setData('reason', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select reason" /></SelectTrigger>
                                            <SelectContent>
                                                {REASONS.map((r) => (
                                                    <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.reason && <p className="mt-1 text-xs text-status-critical">{form.errors.reason}</p>}
                                    </div>
                                    <div>
                                        <Label>Disposal Method</Label>
                                        <Select value={form.data.disposal_method} onValueChange={(v) => form.setData('disposal_method', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select method" /></SelectTrigger>
                                            <SelectContent>
                                                {DISPOSAL_METHODS.map((m) => (
                                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.disposal_method && <p className="mt-1 text-xs text-status-critical">{form.errors.disposal_method}</p>}
                                    </div>
                                </div>

                                {/* Controlled Drug */}
                                <div className="space-y-3 rounded-md border p-3">
                                    <label className="flex items-center gap-2 text-sm font-medium">
                                        <Checkbox
                                            checked={form.data.is_controlled_drug}
                                            onCheckedChange={(v) => form.setData('is_controlled_drug', !!v)}
                                        />
                                        Controlled Drug
                                    </label>
                                    {form.data.is_controlled_drug && (
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <Label>Class</Label>
                                                <Select value={form.data.controlled_drug_class} onValueChange={(v) => form.setData('controlled_drug_class', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select class" /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="B">Class B</SelectItem>
                                                        <SelectItem value="C">Class C</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                {form.errors.controlled_drug_class && <p className="mt-1 text-xs text-status-critical">{form.errors.controlled_drug_class}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="dest-auth-name">Authorised By (Name)</Label>
                                                <Input id="dest-auth-name" value={form.data.authorised_by_name} onChange={(e) => form.setData('authorised_by_name', e.target.value)} />
                                                {form.errors.authorised_by_name && <p className="mt-1 text-xs text-status-critical">{form.errors.authorised_by_name}</p>}
                                            </div>
                                            <div>
                                                <Label htmlFor="dest-auth-reg">Registration No.</Label>
                                                <Input id="dest-auth-reg" value={form.data.authorised_by_registration} onChange={(e) => form.setData('authorised_by_registration', e.target.value)} />
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Witnesses */}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Witness 1 (required)</Label>
                                        <Select value={form.data.witness_1_id} onValueChange={(v) => form.setData('witness_1_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select witness" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.witness_1_id && <p className="mt-1 text-xs text-status-critical">{form.errors.witness_1_id}</p>}
                                    </div>
                                    <div>
                                        <Label>Witness 2 {form.data.is_controlled_drug ? '(required for CD)' : '(optional)'}</Label>
                                        <Select value={form.data.witness_2_id} onValueChange={(v) => form.setData('witness_2_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select witness" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.witness_2_id && <p className="mt-1 text-xs text-status-critical">{form.errors.witness_2_id}</p>}
                                    </div>
                                </div>

                                {/* Notes */}
                                <div>
                                    <Label htmlFor="dest-notes">Notes</Label>
                                    <Textarea id="dest-notes" rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={form.processing}>Record Destruction</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Date</th>
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Medication</th>
                                    <th className="p-3 text-left font-medium">Form</th>
                                    <th className="p-3 text-left font-medium">Qty</th>
                                    <th className="p-3 text-left font-medium">Reason</th>
                                    <th className="p-3 text-left font-medium">Method</th>
                                    <th className="p-3 text-left font-medium">Destroyed By</th>
                                    <th className="p-3 text-left font-medium">Witness 1</th>
                                    <th className="p-3 text-left font-medium">Witness 2</th>
                                    <th className="p-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {destructions.data.map((d: any) => (
                                    <tr key={d.id} className="border-b last:border-0">
                                        <td className="p-3 text-xs">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="p-3">{d.client ? `${d.client.last_name}, ${d.client.first_name}` : '—'}</td>
                                        <td className="p-3">
                                            <span className="font-medium">{d.medication_name}</span>
                                            {d.is_controlled_drug && <Badge variant="destructive" className="ml-1 text-[10px]">CD {d.controlled_drug_class}</Badge>}
                                        </td>
                                        <td className="p-3 text-xs">{d.form ?? '—'} {d.strength ?? ''}</td>
                                        <td className="p-3 font-mono">{d.quantity} {d.unit}</td>
                                        <td className="p-3 text-xs">{d.reason}</td>
                                        <td className="p-3 text-xs">{d.disposal_method}</td>
                                        <td className="p-3 text-xs">{d.destroyed_by_user?.name ?? '—'}</td>
                                        <td className="p-3 text-xs">{d.witness_1?.name ?? '—'}</td>
                                        <td className="p-3 text-xs">{d.witness_2?.name ?? '—'}</td>
                                        <td className="p-3 text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => {
                                                    if (confirm('Are you sure you want to delete this destruction record?')) {
                                                        router.delete(`/emar/destructions/${d.id}`);
                                                    }
                                                }}
                                            >
                                                <Trash2 className="h-4 w-4 text-status-critical" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {destructions.data.length === 0 && (
                                    <tr>
                                        <td colSpan={11} className="p-6 text-center text-muted-foreground">
                                            <Trash2 className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                                            No destruction records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
