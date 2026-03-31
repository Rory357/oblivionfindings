import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, ClipboardCheck, FileWarning, Lock, Package, Plus, Search, Shield, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Props = {
    medications: any[];
    recentEntries: any[];
    discrepancies: any[];
    destructions: any[];
    lossReports: any[];
    staff: { id: number; name: string }[];
    clients: { id: number; first_name: string; last_name: string }[];
};

const ENTRY_TYPES = [
    { value: 'receipt', label: 'Receipt' },
    { value: 'administration', label: 'Administration' },
    { value: 'disposal', label: 'Disposal' },
    { value: 'transfer_in', label: 'Transfer In' },
    { value: 'transfer_out', label: 'Transfer Out' },
    { value: 'balance_check', label: 'Balance Check' },
    { value: 'adjustment', label: 'Adjustment' },
];

const RESOLUTION_ACTIONS = [
    { value: 'counting_error', label: 'Counting error corrected' },
    { value: 'stock_adjustment', label: 'Stock adjustment made' },
    { value: 'incident_reported', label: 'Incident reported' },
    { value: 'other', label: 'Other' },
];

export default function ControlledDrugs({ medications, recentEntries, discrepancies, destructions, lossReports, staff, clients }: Props) {
    // Record CD Entry dialog
    const [entryOpen, setEntryOpen] = useState(false);
    const entryForm = useForm({
        client_id: '',
        medication_name: '',
        entry_type: '',
        quantity: '',
        unit: '',
        on_hand_before: '',
        on_hand_after: '',
        witnessed_by: '',
        batch_number: '',
        notes: '',
    });

    function submitEntry(e: React.FormEvent) {
        e.preventDefault();
        entryForm.post('/emar/controlled/entries', {
            onSuccess: () => {
                setEntryOpen(false);
                entryForm.reset();
            },
        });
    }

    // Balance Check dialog
    const [balanceOpen, setBalanceOpen] = useState(false);
    const balanceForm = useForm({
        client_id: '',
        medication_name: '',
        expected_balance: '',
        actual_balance: '',
        witnessed_by: '',
        discrepancy_notes: '',
    });

    function submitBalanceCheck(e: React.FormEvent) {
        e.preventDefault();
        balanceForm.post('/emar/controlled/balance-check', {
            onSuccess: () => {
                setBalanceOpen(false);
                balanceForm.reset();
            },
        });
    }

    // Resolve Discrepancy dialog
    const [resolveOpen, setResolveOpen] = useState(false);
    const [resolveDiscrepancyId, setResolveDiscrepancyId] = useState<number | null>(null);
    const resolveForm = useForm({
        discrepancy_id: '',
        resolution_notes: '',
        resolution_action: '',
    });

    function openResolve(discrepancyId: number) {
        resolveForm.setData('discrepancy_id', String(discrepancyId));
        setResolveDiscrepancyId(discrepancyId);
        setResolveOpen(true);
    }

    function submitResolve(e: React.FormEvent) {
        e.preventDefault();
        if (!resolveDiscrepancyId) return;

        resolveForm.post(`/emar/controlled/discrepancies/${resolveDiscrepancyId}/resolve`, {
            onSuccess: () => {
                setResolveOpen(false);
                setResolveDiscrepancyId(null);
                resolveForm.reset();
            },
        });
    }

    // Balance Check per-row helper — pre-fills the balance check dialog
    function openBalanceCheckForMed(med: any) {
        balanceForm.setData({
            client_id: String(med.client?.id ?? ''),
            medication_name: med.name ?? '',
            expected_balance: String(med.stock?.on_hand ?? ''),
            actual_balance: '',
            witnessed_by: '',
            discrepancy_notes: '',
        });
        setBalanceOpen(true);
    }

    const hasBalanceDiscrepancy = balanceForm.data.expected_balance !== '' &&
        balanceForm.data.actual_balance !== '' &&
        balanceForm.data.expected_balance !== balanceForm.data.actual_balance;

    // Report Loss dialog
    const [lossOpen, setLossOpen] = useState(false);
    const lossForm = useForm({
        client_id: '',
        medication_name: '',
        quantity_lost: '',
        unit: 'tablets',
        circumstances: '',
        reported_to_police: false,
        police_reference: '',
        reported_to_pharmacy: false,
        pharmacy_name: '',
    });

    function submitLoss(e: React.FormEvent) {
        e.preventDefault();
        lossForm.post('/emar/controlled/loss-reports', {
            onSuccess: () => {
                setLossOpen(false);
                lossForm.reset();
            },
        });
    }

    // Investigate Loss dialog
    const [investigateOpen, setInvestigateOpen] = useState(false);
    const [investigateReportId, setInvestigateReportId] = useState<number | null>(null);
    const investigateForm = useForm({
        investigation_notes: '',
    });

    function openInvestigate(reportId: number) {
        setInvestigateReportId(reportId);
        investigateForm.reset();
        setInvestigateOpen(true);
    }

    function submitInvestigate(e: React.FormEvent) {
        e.preventDefault();
        if (!investigateReportId) return;
        investigateForm.post(`/emar/controlled/loss-reports/${investigateReportId}/investigate`, {
            onSuccess: () => {
                setInvestigateOpen(false);
                setInvestigateReportId(null);
                investigateForm.reset();
            },
        });
    }

    // Resolve Loss dialog
    const [resolveLossOpen, setResolveLossOpen] = useState(false);
    const [resolveLossReportId, setResolveLossReportId] = useState<number | null>(null);
    const resolveLossForm = useForm({
        resolution_outcome: '',
    });

    function openResolveLoss(reportId: number) {
        setResolveLossReportId(reportId);
        resolveLossForm.reset();
        setResolveLossOpen(true);
    }

    function submitResolveLoss(e: React.FormEvent) {
        e.preventDefault();
        if (!resolveLossReportId) return;
        resolveLossForm.post(`/emar/controlled/loss-reports/${resolveLossReportId}/resolve`, {
            onSuccess: () => {
                setResolveLossOpen(false);
                setResolveLossReportId(null);
                resolveLossForm.reset();
            },
        });
    }

    // Reconciliation schedule helper: find last balance check per medication
    function getLastBalanceCheck(medId: number) {
        const balanceChecks = recentEntries.filter(
            (e: any) => e.entry_type === 'balance_check' && e.client_medication_id === medId,
        );
        if (balanceChecks.length === 0) return null;
        return balanceChecks.reduce((latest: any, entry: any) =>
            new Date(entry.recorded_at) > new Date(latest.recorded_at) ? entry : latest,
        );
    }

    function daysSince(dateStr: string | null): number | null {
        if (!dateStr) return null;
        const diff = Date.now() - new Date(dateStr).getTime();
        return Math.floor(diff / (1000 * 60 * 60 * 24));
    }

    const statusVariant = (status: string) => {
        switch (status) {
            case 'open': return 'destructive' as const;
            case 'under_review': return 'secondary' as const;
            case 'closed': return 'outline' as const;
            case 'reported': return 'destructive' as const;
            case 'investigating': return 'secondary' as const;
            case 'resolved': return 'outline' as const;
            default: return 'outline' as const;
        }
    };

    return (
        <AppLayout>
            <Head title="eMAR - Controlled Drugs" />
            <PageHeader title="Controlled Drug Register" description="Controlled substance registers, balance tracking, and discrepancy management." backHref="/emar" actions={
                <div className="flex items-center gap-2">
                    <Button size="sm" onClick={() => setEntryOpen(true)}>
                        <Plus className="mr-1 h-4 w-4" /> Record Entry
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setBalanceOpen(true)}>
                        <ClipboardCheck className="mr-1 h-4 w-4" /> Balance Check
                    </Button>
                    <Button size="sm" variant="destructive" onClick={() => setLossOpen(true)}>
                        <FileWarning className="mr-1 h-4 w-4" /> Report Loss
                    </Button>
                </div>
            } />
            <PageShell>
                    {/* Record CD Entry Dialog */}
                    <Dialog open={entryOpen} onOpenChange={setEntryOpen}>
                        <DialogContent className="max-w-lg">
                            <DialogHeader>
                                <DialogTitle>Record CD Entry</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitEntry} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-client">Client</Label>
                                        <Select value={entryForm.data.client_id} onValueChange={(v) => entryForm.setData('client_id', v)}>
                                            <SelectTrigger id="entry-client">
                                                <SelectValue placeholder="Select client" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>{c.last_name}, {c.first_name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {entryForm.errors.client_id && <p className="text-xs text-destructive">{entryForm.errors.client_id}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-med">Medication Name</Label>
                                        <Input id="entry-med" value={entryForm.data.medication_name} onChange={(e) => entryForm.setData('medication_name', e.target.value)} required />
                                        {entryForm.errors.medication_name && <p className="text-xs text-destructive">{entryForm.errors.medication_name}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-type">Entry Type</Label>
                                        <Select value={entryForm.data.entry_type} onValueChange={(v) => entryForm.setData('entry_type', v)}>
                                            <SelectTrigger id="entry-type">
                                                <SelectValue placeholder="Select type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {ENTRY_TYPES.map((t) => (
                                                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {entryForm.errors.entry_type && <p className="text-xs text-destructive">{entryForm.errors.entry_type}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-witness">Witnessed By</Label>
                                        <Select value={entryForm.data.witnessed_by} onValueChange={(v) => entryForm.setData('witnessed_by', v)}>
                                            <SelectTrigger id="entry-witness">
                                                <SelectValue placeholder="Select witness" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {entryForm.errors.witnessed_by && <p className="text-xs text-destructive">{entryForm.errors.witnessed_by}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-qty">Quantity</Label>
                                        <Input id="entry-qty" type="number" min="0" step="any" value={entryForm.data.quantity} onChange={(e) => entryForm.setData('quantity', e.target.value)} required />
                                        {entryForm.errors.quantity && <p className="text-xs text-destructive">{entryForm.errors.quantity}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-unit">Unit</Label>
                                        <Input id="entry-unit" placeholder="e.g. tablets, ml" value={entryForm.data.unit} onChange={(e) => entryForm.setData('unit', e.target.value)} />
                                        {entryForm.errors.unit && <p className="text-xs text-destructive">{entryForm.errors.unit}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-batch">Batch Number</Label>
                                        <Input id="entry-batch" value={entryForm.data.batch_number} onChange={(e) => entryForm.setData('batch_number', e.target.value)} />
                                        {entryForm.errors.batch_number && <p className="text-xs text-destructive">{entryForm.errors.batch_number}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-before">Balance Before</Label>
                                        <Input id="entry-before" type="number" min="0" step="any" value={entryForm.data.on_hand_before} onChange={(e) => entryForm.setData('on_hand_before', e.target.value)} />
                                        {entryForm.errors.on_hand_before && <p className="text-xs text-destructive">{entryForm.errors.on_hand_before}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-after">Balance After</Label>
                                        <Input id="entry-after" type="number" min="0" step="any" value={entryForm.data.on_hand_after} onChange={(e) => entryForm.setData('on_hand_after', e.target.value)} />
                                        {entryForm.errors.on_hand_after && <p className="text-xs text-destructive">{entryForm.errors.on_hand_after}</p>}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="entry-notes">Notes</Label>
                                    <Textarea id="entry-notes" rows={3} value={entryForm.data.notes} onChange={(e) => entryForm.setData('notes', e.target.value)} />
                                    {entryForm.errors.notes && <p className="text-xs text-destructive">{entryForm.errors.notes}</p>}
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setEntryOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={entryForm.processing}>Record Entry</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Balance Check Dialog */}
                    <Dialog open={balanceOpen} onOpenChange={setBalanceOpen}>
                        <DialogContent className="max-w-md">
                            <DialogHeader>
                                <DialogTitle>Balance Check</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitBalanceCheck} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="bal-client">Client</Label>
                                    <Select value={balanceForm.data.client_id} onValueChange={(v) => balanceForm.setData('client_id', v)}>
                                        <SelectTrigger id="bal-client">
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.last_name}, {c.first_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {balanceForm.errors.client_id && <p className="text-xs text-destructive">{balanceForm.errors.client_id}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="bal-med">Medication Name</Label>
                                    <Input id="bal-med" value={balanceForm.data.medication_name} onChange={(e) => balanceForm.setData('medication_name', e.target.value)} required />
                                    {balanceForm.errors.medication_name && <p className="text-xs text-destructive">{balanceForm.errors.medication_name}</p>}
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="bal-expected">Expected Balance</Label>
                                        <Input id="bal-expected" type="number" min="0" step="any" value={balanceForm.data.expected_balance} onChange={(e) => balanceForm.setData('expected_balance', e.target.value)} required />
                                        {balanceForm.errors.expected_balance && <p className="text-xs text-destructive">{balanceForm.errors.expected_balance}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="bal-actual">Actual Balance</Label>
                                        <Input id="bal-actual" type="number" min="0" step="any" value={balanceForm.data.actual_balance} onChange={(e) => balanceForm.setData('actual_balance', e.target.value)} required />
                                        {balanceForm.errors.actual_balance && <p className="text-xs text-destructive">{balanceForm.errors.actual_balance}</p>}
                                    </div>
                                </div>

                                {hasBalanceDiscrepancy && (
                                    <div className="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950">
                                        <p className="mb-2 flex items-center gap-1 text-sm font-medium text-red-700 dark:text-red-400">
                                            <AlertTriangle className="h-3.5 w-3.5" /> Discrepancy detected
                                        </p>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="bal-disc-notes">Discrepancy Notes</Label>
                                            <Textarea id="bal-disc-notes" rows={3} value={balanceForm.data.discrepancy_notes} onChange={(e) => balanceForm.setData('discrepancy_notes', e.target.value)} placeholder="Describe the discrepancy..." />
                                            {balanceForm.errors.discrepancy_notes && <p className="text-xs text-destructive">{balanceForm.errors.discrepancy_notes}</p>}
                                        </div>
                                    </div>
                                )}

                                <div className="space-y-1.5">
                                    <Label htmlFor="bal-witness">Witnessed By</Label>
                                    <Select value={balanceForm.data.witnessed_by} onValueChange={(v) => balanceForm.setData('witnessed_by', v)}>
                                        <SelectTrigger id="bal-witness">
                                            <SelectValue placeholder="Select witness" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {balanceForm.errors.witnessed_by && <p className="text-xs text-destructive">{balanceForm.errors.witnessed_by}</p>}
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setBalanceOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={balanceForm.processing}>Submit Balance Check</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Report Loss Dialog */}
                    <Dialog open={lossOpen} onOpenChange={setLossOpen}>
                        <DialogContent className="max-w-lg">
                            <DialogHeader>
                                <DialogTitle>Report Controlled Drug Loss</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitLoss} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="loss-client">Client</Label>
                                        <Select value={lossForm.data.client_id} onValueChange={(v) => lossForm.setData('client_id', v)}>
                                            <SelectTrigger id="loss-client">
                                                <SelectValue placeholder="Select client" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>{c.last_name}, {c.first_name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {lossForm.errors.client_id && <p className="text-xs text-destructive">{lossForm.errors.client_id}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="loss-med">Medication Name</Label>
                                        <Input id="loss-med" value={lossForm.data.medication_name} onChange={(e) => lossForm.setData('medication_name', e.target.value)} required />
                                        {lossForm.errors.medication_name && <p className="text-xs text-destructive">{lossForm.errors.medication_name}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="loss-qty">Quantity Lost</Label>
                                        <Input id="loss-qty" type="number" min="0.01" step="any" value={lossForm.data.quantity_lost} onChange={(e) => lossForm.setData('quantity_lost', e.target.value)} required />
                                        {lossForm.errors.quantity_lost && <p className="text-xs text-destructive">{lossForm.errors.quantity_lost}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="loss-unit">Unit</Label>
                                        <Input id="loss-unit" placeholder="e.g. tablets, ml" value={lossForm.data.unit} onChange={(e) => lossForm.setData('unit', e.target.value)} />
                                        {lossForm.errors.unit && <p className="text-xs text-destructive">{lossForm.errors.unit}</p>}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="loss-circumstances">Circumstances</Label>
                                    <Textarea id="loss-circumstances" rows={3} value={lossForm.data.circumstances} onChange={(e) => lossForm.setData('circumstances', e.target.value)} required placeholder="Describe how the loss was discovered..." />
                                    {lossForm.errors.circumstances && <p className="text-xs text-destructive">{lossForm.errors.circumstances}</p>}
                                </div>

                                <div className="space-y-3 rounded-md border p-3">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="loss-police"
                                            checked={lossForm.data.reported_to_police}
                                            onCheckedChange={(checked) => lossForm.setData('reported_to_police', checked === true)}
                                        />
                                        <Label htmlFor="loss-police" className="cursor-pointer">Reported to Police</Label>
                                    </div>
                                    {lossForm.data.reported_to_police && (
                                        <div className="space-y-1.5 pl-6">
                                            <Label htmlFor="loss-police-ref">Police Reference</Label>
                                            <Input id="loss-police-ref" value={lossForm.data.police_reference} onChange={(e) => lossForm.setData('police_reference', e.target.value)} placeholder="Reference number" />
                                            {lossForm.errors.police_reference && <p className="text-xs text-destructive">{lossForm.errors.police_reference}</p>}
                                        </div>
                                    )}

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="loss-pharmacy"
                                            checked={lossForm.data.reported_to_pharmacy}
                                            onCheckedChange={(checked) => lossForm.setData('reported_to_pharmacy', checked === true)}
                                        />
                                        <Label htmlFor="loss-pharmacy" className="cursor-pointer">Reported to Pharmacy</Label>
                                    </div>
                                    {lossForm.data.reported_to_pharmacy && (
                                        <div className="space-y-1.5 pl-6">
                                            <Label htmlFor="loss-pharmacy-name">Pharmacy Name</Label>
                                            <Input id="loss-pharmacy-name" value={lossForm.data.pharmacy_name} onChange={(e) => lossForm.setData('pharmacy_name', e.target.value)} placeholder="Pharmacy name" />
                                            {lossForm.errors.pharmacy_name && <p className="text-xs text-destructive">{lossForm.errors.pharmacy_name}</p>}
                                        </div>
                                    )}
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setLossOpen(false)}>Cancel</Button>
                                    <Button type="submit" variant="destructive" disabled={lossForm.processing}>Submit Loss Report</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Investigate Loss Dialog */}
                    <Dialog open={investigateOpen} onOpenChange={setInvestigateOpen}>
                        <DialogContent className="max-w-md">
                            <DialogHeader>
                                <DialogTitle>Investigate Loss Report</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitInvestigate} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="investigate-notes">Investigation Notes</Label>
                                    <Textarea id="investigate-notes" rows={4} value={investigateForm.data.investigation_notes} onChange={(e) => investigateForm.setData('investigation_notes', e.target.value)} required placeholder="Document investigation findings..." />
                                    {investigateForm.errors.investigation_notes && <p className="text-xs text-destructive">{investigateForm.errors.investigation_notes}</p>}
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setInvestigateOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={investigateForm.processing}>Save Investigation Notes</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    {/* Resolve Loss Dialog */}
                    <Dialog open={resolveLossOpen} onOpenChange={setResolveLossOpen}>
                        <DialogContent className="max-w-md">
                            <DialogHeader>
                                <DialogTitle>Resolve Loss Report</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitResolveLoss} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="resolve-loss-outcome">Resolution Outcome</Label>
                                    <Textarea id="resolve-loss-outcome" rows={4} value={resolveLossForm.data.resolution_outcome} onChange={(e) => resolveLossForm.setData('resolution_outcome', e.target.value)} required placeholder="Describe the resolution outcome..." />
                                    {resolveLossForm.errors.resolution_outcome && <p className="text-xs text-destructive">{resolveLossForm.errors.resolution_outcome}</p>}
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setResolveLossOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={resolveLossForm.processing}>Resolve Loss Report</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                {/* Discrepancy Alert */}
                {discrepancies.length > 0 && (
                    <Card className="mb-6 border-red-200 dark:border-red-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-red-700 dark:text-red-400">
                                <AlertTriangle className="h-4 w-4" /> Active Discrepancies ({discrepancies.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {discrepancies.map((d: any) => (
                                    <div key={d.id} className="flex items-center justify-between p-3">
                                        <div>
                                            <span className="font-medium">{d.client?.last_name}, {d.client?.first_name}</span>
                                            <span className="mx-2 text-muted-foreground">—</span>
                                            <span className="text-sm">{d.medication?.name}</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={statusVariant(d.status)}>{d.status}</Badge>
                                            <Button size="sm" variant="outline" onClick={() => openResolve(d.id)}>
                                                <CheckCircle className="mr-1 h-3.5 w-3.5" /> Resolve
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Resolve Discrepancy Dialog */}
                <Dialog open={resolveOpen} onOpenChange={setResolveOpen}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>Resolve Discrepancy</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submitResolve} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="resolve-action">Resolution Action</Label>
                                <Select value={resolveForm.data.resolution_action} onValueChange={(v) => resolveForm.setData('resolution_action', v)}>
                                    <SelectTrigger id="resolve-action">
                                        <SelectValue placeholder="Select action taken" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {RESOLUTION_ACTIONS.map((a) => (
                                            <SelectItem key={a.value} value={a.value}>{a.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {resolveForm.errors.resolution_action && <p className="text-xs text-destructive">{resolveForm.errors.resolution_action}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="resolve-notes">Resolution Notes</Label>
                                <Textarea id="resolve-notes" rows={3} value={resolveForm.data.resolution_notes} onChange={(e) => resolveForm.setData('resolution_notes', e.target.value)} required placeholder="Describe how the discrepancy was resolved..." />
                                {resolveForm.errors.resolution_notes && <p className="text-xs text-destructive">{resolveForm.errors.resolution_notes}</p>}
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setResolveOpen(false)}>Cancel</Button>
                                <Button type="submit" disabled={resolveForm.processing}>Resolve Discrepancy</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Tabs defaultValue="register">
                    <TabsList className="mb-4">
                        <TabsTrigger value="register"><Lock className="mr-1 h-3.5 w-3.5" /> Register</TabsTrigger>
                        <TabsTrigger value="entries"><Package className="mr-1 h-3.5 w-3.5" /> Recent Entries</TabsTrigger>
                        <TabsTrigger value="destructions"><Trash2 className="mr-1 h-3.5 w-3.5" /> Destructions</TabsTrigger>
                        <TabsTrigger value="losses"><FileWarning className="mr-1 h-3.5 w-3.5" /> Loss Reports</TabsTrigger>
                    </TabsList>

                    {/* Register Tab */}
                    <TabsContent value="register">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">On Hand</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {medications.map((m: any) => (
                                            <tr key={m.id} className="border-b last:border-0">
                                                <td className="p-3">{m.client?.last_name}, {m.client?.first_name}</td>
                                                <td className="p-3 font-medium">{m.name}</td>
                                                <td className="p-3">
                                                    <span className="font-mono text-sm">{m.stock?.on_hand ?? '—'}</span>
                                                    {m.stock?.unit && <span className="ml-1 text-xs text-muted-foreground">{m.stock.unit}</span>}
                                                </td>
                                                <td className="p-3">
                                                    {m.stock?.reorder_level && m.stock?.on_hand <= m.stock.reorder_level ? (
                                                        <Badge variant="destructive" className="text-xs">Low Stock</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-xs">OK</Badge>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    <Button size="sm" variant="ghost" onClick={() => openBalanceCheckForMed(m)}>
                                                        <ClipboardCheck className="mr-1 h-3.5 w-3.5" /> Check Balance
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                        {medications.length === 0 && (
                                            <tr><td colSpan={5} className="p-6 text-center text-muted-foreground">No active controlled drugs.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>

                        {/* Reconciliation Schedule */}
                        {medications.length > 0 && (
                            <Card className="mt-4">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <ClipboardCheck className="h-4 w-4" /> Reconciliation Schedule
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">Client</th>
                                                <th className="p-3 text-left font-medium">Medication</th>
                                                <th className="p-3 text-left font-medium">Last Balance Check</th>
                                                <th className="p-3 text-left font-medium">Days Since</th>
                                                <th className="p-3 text-right font-medium">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {medications.map((m: any) => {
                                                const lastCheck = getLastBalanceCheck(m.id);
                                                const days = lastCheck ? daysSince(lastCheck.recorded_at) : null;
                                                const overdue = days === null || days >= 7;
                                                return (
                                                    <tr key={`recon-${m.id}`} className="border-b last:border-0">
                                                        <td className="p-3">{m.client?.last_name}, {m.client?.first_name}</td>
                                                        <td className="p-3 font-medium">{m.name}</td>
                                                        <td className="p-3 text-xs">
                                                            {lastCheck
                                                                ? new Date(lastCheck.recorded_at).toLocaleString('en-NZ', { dateStyle: 'short', timeStyle: 'short' })
                                                                : 'Never checked'}
                                                        </td>
                                                        <td className="p-3">
                                                            {overdue ? (
                                                                <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-100 dark:bg-amber-900 dark:text-amber-300">
                                                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                                                    {days !== null ? `${days} days` : 'Overdue'}
                                                                </Badge>
                                                            ) : (
                                                                <span className="text-xs text-muted-foreground">{days} days ago</span>
                                                            )}
                                                        </td>
                                                        <td className="p-3 text-right">
                                                            <Button size="sm" variant={overdue ? 'default' : 'ghost'} onClick={() => openBalanceCheckForMed(m)}>
                                                                <ClipboardCheck className="mr-1 h-3.5 w-3.5" /> Check
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* Recent Entries Tab */}
                    <TabsContent value="entries">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date/Time</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Balance</th>
                                            <th className="p-3 text-left font-medium">Recorded By</th>
                                            <th className="p-3 text-left font-medium">Witness</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentEntries.map((e: any) => (
                                            <tr key={e.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { dateStyle: 'short', timeStyle: 'short' }) : '—'}</td>
                                                <td className="p-3">{e.client?.last_name}, {e.client?.first_name}</td>
                                                <td className="p-3 font-medium">{e.medication?.name}</td>
                                                <td className="p-3"><Badge variant="outline" className="text-xs">{e.entry_type}</Badge></td>
                                                <td className="p-3 font-mono">{e.quantity}</td>
                                                <td className="p-3 font-mono">{e.on_hand_after}</td>
                                                <td className="p-3 text-xs">{e.recorded_by?.name ?? '—'}</td>
                                                <td className="p-3 text-xs">{e.witnessed_by?.name ?? '—'}</td>
                                            </tr>
                                        ))}
                                        {recentEntries.length === 0 && (
                                            <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No recent entries.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Destructions Tab */}
                    <TabsContent value="destructions">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Reason</th>
                                            <th className="p-3 text-left font-medium">Destroyed By</th>
                                            <th className="p-3 text-left font-medium">Witness</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {destructions.map((d: any) => (
                                            <tr key={d.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                                <td className="p-3">{d.client?.last_name}, {d.client?.first_name}</td>
                                                <td className="p-3 font-medium">{d.medication_name}</td>
                                                <td className="p-3 font-mono">{d.quantity} {d.unit}</td>
                                                <td className="p-3 text-xs">{d.reason}</td>
                                                <td className="p-3 text-xs">{d.destroyed_by_user?.name ?? '—'}</td>
                                                <td className="p-3 text-xs">{d.witness_1?.name ?? '—'}</td>
                                            </tr>
                                        ))}
                                        {destructions.length === 0 && (
                                            <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No controlled drug destructions recorded.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Loss Reports Tab */}
                    <TabsContent value="losses">
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Client</th>
                                            <th className="p-3 text-left font-medium">Medication</th>
                                            <th className="p-3 text-left font-medium">Qty</th>
                                            <th className="p-3 text-left font-medium">Circumstances</th>
                                            <th className="p-3 text-left font-medium">Police</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lossReports.map((r: any) => (
                                            <tr key={r.id} className="border-b last:border-0">
                                                <td className="p-3 text-xs">{r.discovered_at ? new Date(r.discovered_at).toLocaleDateString('en-NZ') : '—'}</td>
                                                <td className="p-3">{r.client ? `${r.client.last_name}, ${r.client.first_name}` : '—'}</td>
                                                <td className="p-3 font-medium">{r.medication_name}</td>
                                                <td className="p-3 font-mono">{r.quantity_lost} {r.unit}</td>
                                                <td className="p-3 text-xs max-w-[200px] truncate" title={r.circumstances}>{r.circumstances}</td>
                                                <td className="p-3">
                                                    {r.reported_to_police ? (
                                                        <Badge variant="outline" className="bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                                            <Shield className="mr-1 h-3 w-3" /> Yes
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-muted-foreground">No</Badge>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    <Badge variant={statusVariant(r.investigation_status)}>{r.investigation_status}</Badge>
                                                </td>
                                                <td className="p-3 text-right">
                                                    {r.investigation_status !== 'resolved' && (
                                                        <div className="flex items-center justify-end gap-1">
                                                            {r.investigation_status === 'reported' && (
                                                                <Button size="sm" variant="outline" onClick={() => openInvestigate(r.id)}>
                                                                    <Search className="mr-1 h-3.5 w-3.5" /> Investigate
                                                                </Button>
                                                            )}
                                                            <Button size="sm" variant="outline" onClick={() => openResolveLoss(r.id)}>
                                                                <CheckCircle className="mr-1 h-3.5 w-3.5" /> Resolve
                                                            </Button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                        {lossReports.length === 0 && (
                                            <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No controlled drug loss reports.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
