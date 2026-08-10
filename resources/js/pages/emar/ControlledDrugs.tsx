import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, ClipboardCheck, Lock, Package, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Props = {
    medications: any[];
    recentEntries: any[];
    discrepancies: any[];
    destructions: any[];
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

export default function ControlledDrugs({ medications, recentEntries, discrepancies, destructions, staff, clients }: Props) {
    // Record CD Entry dialog
    const [entryOpen, setEntryOpen] = useState(false);
    const entryForm = useForm({
        client_id: '',
        medication_name: '',
        entry_type: '',
        quantity: '',
        unit: '',
        balance_before: '',
        balance_after: '',
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
        resolveForm.post('/emar/controlled/resolve-discrepancy', {
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
                                        <Input id="entry-before" type="number" min="0" step="any" value={entryForm.data.balance_before} onChange={(e) => entryForm.setData('balance_before', e.target.value)} />
                                        {entryForm.errors.balance_before && <p className="text-xs text-destructive">{entryForm.errors.balance_before}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="entry-after">Balance After</Label>
                                        <Input id="entry-after" type="number" min="0" step="any" value={entryForm.data.balance_after} onChange={(e) => entryForm.setData('balance_after', e.target.value)} />
                                        {entryForm.errors.balance_after && <p className="text-xs text-destructive">{entryForm.errors.balance_after}</p>}
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
                                            <Badge variant="destructive">{d.status}</Badge>
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
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
