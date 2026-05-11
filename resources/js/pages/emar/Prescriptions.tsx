import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, PenTool, Plus, Shield, X } from 'lucide-react';
import { useState } from 'react';

type Props = {
    orders: { data: any[]; links: any };
    pendingCountersigns: number;
    covertAuthorisations: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    filters: { status?: string; client_id?: string };
};

const orderStatusColors: Record<string, string> = {
    pending: 'bg-status-warning-bg text-status-warning',
    confirmed: 'bg-status-info-bg text-status-info',
    dispensed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-muted text-muted-foreground',
    expired: 'bg-status-critical-bg text-status-critical',
};

function NewOrderDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        client_id: '',
        order_type: 'new',
        prescriber_name: '',
        prescriber_registration: '',
        prescriber_type: 'gp',
        medication_name: '',
        dose: '',
        route: '',
        frequency: '',
        instructions: '',
        indication: '',
        order_date: '',
        effective_date: '',
        expiry_date: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/prescriptions', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    const isVerbalOrTelephone = form.data.order_type === 'verbal' || form.data.order_type === 'telephone';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1 h-4 w-4" /> New Prescriber Order
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New Prescriber Order</DialogTitle>
                    <DialogDescription>
                        Capture a new or changed prescriber order, including
                        verbal and telephone orders.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    {isVerbalOrTelephone && (
                        <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-3 dark:border-status-warning/30">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                            <p className="text-sm text-status-warning dark:text-status-warning">
                                Verbal and telephone orders require prescriber countersignature within 72 hours.
                            </p>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="order_client_id">Client *</Label>
                            <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                                <SelectTrigger id="order_client_id">
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
                            {form.errors.client_id && <p className="text-xs text-status-critical">{form.errors.client_id}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="order_type">Order Type *</Label>
                            <Select value={form.data.order_type} onValueChange={(v) => form.setData('order_type', v)}>
                                <SelectTrigger id="order_type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="new">New</SelectItem>
                                    <SelectItem value="change">Change</SelectItem>
                                    <SelectItem value="cease">Cease</SelectItem>
                                    <SelectItem value="verbal">Verbal</SelectItem>
                                    <SelectItem value="telephone">Telephone</SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.order_type && <p className="text-xs text-status-critical">{form.errors.order_type}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="prescriber_name">Prescriber Name *</Label>
                            <Input
                                id="prescriber_name"
                                value={form.data.prescriber_name}
                                onChange={(e) => form.setData('prescriber_name', e.target.value)}
                            />
                            {form.errors.prescriber_name && <p className="text-xs text-status-critical">{form.errors.prescriber_name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="prescriber_registration">Registration #</Label>
                            <Input
                                id="prescriber_registration"
                                value={form.data.prescriber_registration}
                                onChange={(e) => form.setData('prescriber_registration', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="prescriber_type">Prescriber Type *</Label>
                            <Select value={form.data.prescriber_type} onValueChange={(v) => form.setData('prescriber_type', v)}>
                                <SelectTrigger id="prescriber_type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="gp">GP</SelectItem>
                                    <SelectItem value="specialist">Specialist</SelectItem>
                                    <SelectItem value="nurse_practitioner">Nurse Practitioner</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="medication_name">Medication Name *</Label>
                        <Input
                            id="medication_name"
                            value={form.data.medication_name}
                            onChange={(e) => form.setData('medication_name', e.target.value)}
                        />
                        {form.errors.medication_name && <p className="text-xs text-status-critical">{form.errors.medication_name}</p>}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="dose">Dose *</Label>
                            <Input id="dose" value={form.data.dose} onChange={(e) => form.setData('dose', e.target.value)} />
                            {form.errors.dose && <p className="text-xs text-status-critical">{form.errors.dose}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="route">Route *</Label>
                            <Input id="route" value={form.data.route} onChange={(e) => form.setData('route', e.target.value)} />
                            {form.errors.route && <p className="text-xs text-status-critical">{form.errors.route}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="frequency">Frequency *</Label>
                            <Input id="frequency" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)} />
                            {form.errors.frequency && <p className="text-xs text-status-critical">{form.errors.frequency}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="instructions">Instructions</Label>
                        <Textarea
                            id="instructions"
                            rows={3}
                            value={form.data.instructions}
                            onChange={(e) => form.setData('instructions', e.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="indication">Indication</Label>
                            <Input
                                id="indication"
                                value={form.data.indication}
                                onChange={(e) => form.setData('indication', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="order_date">Order Date *</Label>
                            <Input
                                id="order_date"
                                type="date"
                                value={form.data.order_date}
                                onChange={(e) => form.setData('order_date', e.target.value)}
                            />
                            {form.errors.order_date && <p className="text-xs text-status-critical">{form.errors.order_date}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="effective_date">Effective Date</Label>
                            <Input
                                id="effective_date"
                                type="date"
                                value={form.data.effective_date}
                                onChange={(e) => form.setData('effective_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="expiry_date">Expiry Date</Label>
                            <Input
                                id="expiry_date"
                                type="date"
                                value={form.data.expiry_date}
                                onChange={(e) => form.setData('expiry_date', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Create Order'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function NewCovertDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        client_id: '',
        client_medication_id: '',
        authorised_by_name: '',
        authorised_by_registration: '',
        clinical_justification: '',
        legal_basis: '',
        administration_method: '',
        pharmacist_advice: '',
        authorised_date: '',
        review_date: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/prescriptions/covert', {
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
                    <Plus className="mr-1 h-4 w-4" /> New Covert Authorisation
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New Covert Administration Authorisation</DialogTitle>
                    <DialogDescription>
                        Record the legal and clinical approvals for covert
                        medication administration.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="covert_client_id">Client *</Label>
                            <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                                <SelectTrigger id="covert_client_id">
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
                            {form.errors.client_id && <p className="text-xs text-status-critical">{form.errors.client_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="client_medication_id">Medication *</Label>
                            <Input
                                id="client_medication_id"
                                placeholder="Medication name or ID"
                                value={form.data.client_medication_id}
                                onChange={(e) => form.setData('client_medication_id', e.target.value)}
                            />
                            {form.errors.client_medication_id && <p className="text-xs text-status-critical">{form.errors.client_medication_id}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="authorised_by_name">Authorised By (Name) *</Label>
                            <Input
                                id="authorised_by_name"
                                value={form.data.authorised_by_name}
                                onChange={(e) => form.setData('authorised_by_name', e.target.value)}
                            />
                            {form.errors.authorised_by_name && <p className="text-xs text-status-critical">{form.errors.authorised_by_name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="authorised_by_registration">Registration #</Label>
                            <Input
                                id="authorised_by_registration"
                                value={form.data.authorised_by_registration}
                                onChange={(e) => form.setData('authorised_by_registration', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="clinical_justification">Clinical Justification *</Label>
                        <Textarea
                            id="clinical_justification"
                            rows={3}
                            value={form.data.clinical_justification}
                            onChange={(e) => form.setData('clinical_justification', e.target.value)}
                        />
                        {form.errors.clinical_justification && <p className="text-xs text-status-critical">{form.errors.clinical_justification}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="legal_basis">Legal Basis</Label>
                            <Input
                                id="legal_basis"
                                value={form.data.legal_basis}
                                onChange={(e) => form.setData('legal_basis', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="administration_method">Administration Method</Label>
                            <Input
                                id="administration_method"
                                value={form.data.administration_method}
                                onChange={(e) => form.setData('administration_method', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="pharmacist_advice">Pharmacist Advice</Label>
                        <Textarea
                            id="pharmacist_advice"
                            rows={2}
                            value={form.data.pharmacist_advice}
                            onChange={(e) => form.setData('pharmacist_advice', e.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="authorised_date">Authorised Date *</Label>
                            <Input
                                id="authorised_date"
                                type="date"
                                value={form.data.authorised_date}
                                onChange={(e) => form.setData('authorised_date', e.target.value)}
                            />
                            {form.errors.authorised_date && <p className="text-xs text-status-critical">{form.errors.authorised_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="review_date">Review Date</Label>
                            <Input
                                id="review_date"
                                type="date"
                                value={form.data.review_date}
                                onChange={(e) => form.setData('review_date', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Create Authorisation'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Prescriptions({ orders, pendingCountersigns, covertAuthorisations, clients, staff, filters }: Props) {
    function updateFilter(key: string, value: string) {
        router.get('/emar/prescriptions', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    function handleCountersign(orderId: number) {
        if (confirm('Countersign this order? This confirms the prescriber has verified and signed the order.')) {
            router.post(`/emar/prescriptions/${orderId}/countersign`);
        }
    }

    function handleCancelOrder(orderId: number) {
        if (confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
            router.delete(`/emar/prescriptions/${orderId}`);
        }
    }

    function handleRevokeCovert(id: number) {
        if (confirm('Revoke this covert administration authorisation? The medication will revert to standard administration.')) {
            router.post(`/emar/prescriptions/covert/${id}/revoke`);
        }
    }

    return (
        <AppLayout>
            <Head title="eMAR - Prescriptions" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Prescriptions & Orders"
                    description="Prescriber orders, verbal/telephone orders, countersignatures, and covert administration authorisations"
                    icon={<FileText className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Alerts */}
                {pendingCountersigns > 0 && (
                    <Card className="mb-6 border-status-warning/30 dark:border-status-warning/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <PenTool className="h-5 w-5 text-status-warning" />
                            <span className="text-sm font-medium text-status-warning dark:text-status-warning">{pendingCountersigns} order(s) awaiting prescriber countersignature</span>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="mb-6 flex flex-wrap gap-3">
                    <Select value={filters.status ?? ''} onValueChange={(v) => updateFilter('status', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="confirmed">Confirmed</SelectItem>
                            <SelectItem value="dispensed">Dispensed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
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
                </div>

                <Tabs defaultValue="orders">
                    <TabsList className="mb-4">
                        <TabsTrigger value="orders"><FileText className="mr-1 h-3.5 w-3.5" /> Prescriber Orders</TabsTrigger>
                        <TabsTrigger value="covert"><Shield className="mr-1 h-3.5 w-3.5" /> Covert Authorisations ({covertAuthorisations.length})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="orders">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="text-base">Prescriber Orders</CardTitle>
                                <NewOrderDialog clients={clients} />
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Prescriber</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Countersign</th>
                                            <th className="p-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.data.map((o: any) => (
                                            <tr key={o.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{o.order_date ? new Date(o.order_date).toLocaleDateString('en-NZ') : '—'}</td>
                                                <td className="p-3">{o.client?.last_name}, {o.client?.first_name}</td>
                                                <td className="p-3 font-medium">{o.medication_name}</td>
                                                <td className="p-3"><Badge variant="outline" className="text-xs">{o.order_type}</Badge></td>
                                                <td className="p-3 text-xs">
                                                    {o.prescriber_name}
                                                    {o.prescriber_registration && <span className="ml-1 text-muted-foreground">({o.prescriber_registration})</span>}
                                                </td>
                                                <td className="p-3">
                                                    <Badge className={`text-xs ${orderStatusColors[o.status] ?? ''}`}>{o.status}</Badge>
                                                </td>
                                                <td className="p-3">
                                                    {o.requires_countersign && !o.countersigned_at ? (
                                                        <Button size="sm" variant="destructive" className="h-6 text-[10px]" onClick={() => handleCountersign(o.id)}>
                                                            <PenTool className="mr-1 h-3 w-3" /> Countersign
                                                        </Button>
                                                    ) : o.countersigned_at ? (
                                                        <span className="text-xs text-status-success">Done</span>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">N/A</span>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    {o.status === 'pending' && (
                                                        <Button size="sm" variant="ghost" className="h-6 text-xs text-status-critical hover:text-status-critical" onClick={() => handleCancelOrder(o.id)}>
                                                            <X className="mr-1 h-3 w-3" /> Cancel
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {orders.data.length === 0 && (
                                            <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No prescriber orders found.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="covert">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="text-base">Active Covert Administration Authorisations</CardTitle>
                                <NewCovertDialog clients={clients} />
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Authorised By</th>
                                            <th className="p-3 text-left font-medium">Method</th>
                                            <th className="p-3 text-left font-medium">Review Date</th>
                                            <th className="p-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {covertAuthorisations.map((c: any) => (
                                            <tr key={c.id} className="border-b last:border-0">
                                                <td className="p-3">{c.client?.last_name}, {c.client?.first_name}</td>
                                                <td className="p-3 font-medium">{c.medication?.name}</td>
                                                <td className="p-3 text-xs">{c.authorised_by_name}</td>
                                                <td className="p-3 text-xs">{c.administration_method ?? '—'}</td>
                                                <td className="p-3 text-xs">
                                                    {c.review_date ? new Date(c.review_date).toLocaleDateString('en-NZ') : '—'}
                                                    {c.review_date && new Date(c.review_date) < new Date() && (
                                                        <Badge variant="destructive" className="ml-1 text-[10px]">Overdue</Badge>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    {!c.revoked_at && (
                                                        <Button size="sm" variant="ghost" className="h-6 text-xs text-status-critical hover:text-status-critical" onClick={() => handleRevokeCovert(c.id)}>
                                                            <X className="mr-1 h-3 w-3" /> Revoke
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {covertAuthorisations.length === 0 && (
                                            <tr><td colSpan={6} className="p-6 text-center text-muted-foreground">No active covert authorisations.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
