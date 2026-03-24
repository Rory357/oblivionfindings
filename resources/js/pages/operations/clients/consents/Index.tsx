import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileCheck,
    Plus,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

const STATUS_STYLES: Record<string, { bg: string; icon: typeof CheckCircle2 }> = {
    given: { bg: 'bg-emerald-100 text-emerald-700', icon: CheckCircle2 },
    refused: { bg: 'bg-red-100 text-red-700', icon: XCircle },
    withdrawn: { bg: 'bg-slate-100 text-slate-600', icon: XCircle },
    expired: { bg: 'bg-amber-100 text-amber-700', icon: Clock },
};

const CATEGORY_COLORS: Record<string, string> = {
    medical: 'bg-red-50 border-red-200',
    care: 'bg-violet-50 border-violet-200',
    communication: 'bg-blue-50 border-blue-200',
    data_protection: 'bg-indigo-50 border-indigo-200',
    safety: 'bg-amber-50 border-amber-200',
    activities: 'bg-emerald-50 border-emerald-200',
    safeguarding: 'bg-orange-50 border-orange-200',
    essential: 'bg-cyan-50 border-cyan-200',
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    consents: any[];
    stats: { total: number; active: number; expiring_soon: number; expired: number; withdrawn: number };
    consent_types: any[];
};

export default function ConsentsIndex({ client, consents = [], stats = {} as any, consent_types = [] }: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`;

    const [showRecord, setShowRecord] = useState(false);
    const [showWithdraw, setShowWithdraw] = useState<number | null>(null);
    const [formData, setFormData] = useState({
        consent_type_id: '', status: 'given', given_method: 'written', given_at: new Date().toISOString().split('T')[0],
        given_by_relationship: '', given_notes: '', expires_at: '', evidence_type: '',
        capacity_assessed: false, capacity_outcome: '', capacity_notes: '',
        refusal_reason: '',
    });
    const [withdrawReason, setWithdrawReason] = useState('');

    const s = { total: stats?.total ?? 0, active: stats?.active ?? 0, expiring_soon: stats?.expiring_soon ?? 0, expired: stats?.expired ?? 0, withdrawn: stats?.withdrawn ?? 0 };

    const submitConsent = () => {
        router.post(`/operations/clients/${client.id}/consents`, formData, {
            preserveScroll: true,
            onSuccess: () => { setShowRecord(false); setFormData({ ...formData, consent_type_id: '', given_notes: '', expires_at: '', capacity_assessed: false, capacity_notes: '', refusal_reason: '' }); },
        });
    };

    const submitWithdraw = () => {
        if (!showWithdraw) return;
        router.post(`/operations/clients/${client.id}/consents/${showWithdraw}/withdraw`, { withdrawal_reason: withdrawReason }, {
            preserveScroll: true,
            onSuccess: () => { setShowWithdraw(null); setWithdrawReason(''); },
        });
    };

    // Group by category
    const grouped: Record<string, any[]> = {};
    consents.forEach(c => {
        const cat = c.consent_type?.category ?? c.category ?? 'other';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(c);
    });

    return (
        <AppLayout breadcrumbs={[
            { title: labels?.['client.plural'] ?? 'Clients', href: '/operations/clients' },
            { title: name, href: `/operations/clients/${client.id}` },
            { title: 'Consents' },
        ]}>
            <Head title={`Consents - ${name}`} />
            <PageHeader
                title="Consent Management"
                description={`Manage consent records for ${name}.`}
                backHref={`/operations/clients/${client.id}`}
                actions={
                    <Button className="gap-1.5 bg-violet-600 hover:bg-violet-700" onClick={() => setShowRecord(true)}>
                        <Plus className="h-4 w-4" /> Record Consent
                    </Button>
                }
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {[
                        { label: 'Total', value: s.total, color: 'text-violet-700', bg: 'from-violet-50 to-purple-50' },
                        { label: 'Active', value: s.active, color: 'text-emerald-700', bg: 'from-emerald-50 to-green-50' },
                        { label: 'Expiring', value: s.expiring_soon, color: s.expiring_soon > 0 ? 'text-amber-700' : 'text-slate-400', bg: 'from-amber-50 to-yellow-50' },
                        { label: 'Expired', value: s.expired, color: s.expired > 0 ? 'text-red-700' : 'text-slate-400', bg: 'from-red-50 to-rose-50' },
                        { label: 'Withdrawn', value: s.withdrawn, color: 'text-slate-600', bg: 'from-slate-50 to-slate-100' },
                    ].map(st => (
                        <div key={st.label} className={`rounded-xl border bg-gradient-to-br ${st.bg} p-3 text-center`}>
                            <div className={`text-xl font-bold ${st.color}`}>{st.value}</div>
                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">{st.label}</div>
                        </div>
                    ))}
                </div>

                {/* Consent Cards grouped by category */}
                {consents.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                <ShieldCheck className="h-8 w-8 text-violet-400" />
                            </div>
                            <p className="font-medium">No Consent Records</p>
                            <p className="mt-1 text-sm text-muted-foreground">Record the first consent for {client.first_name}.</p>
                            <Button className="mt-4 gap-1.5 bg-violet-600 hover:bg-violet-700" size="sm" onClick={() => setShowRecord(true)}>
                                <Plus className="h-3.5 w-3.5" /> Record Consent
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    Object.entries(grouped).map(([cat, items]) => (
                        <div key={cat}>
                            <div className="mb-2 flex items-center gap-2">
                                <FileCheck className="h-4 w-4 text-violet-500" />
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{cat.replace(/_/g, ' ')}</span>
                                <Badge variant="secondary" className="text-[10px]">{items.length}</Badge>
                            </div>
                            <div className="space-y-2">
                                {items.map((c: any) => {
                                    const displayStatus = c.is_expired ? 'expired' : c.status;
                                    const style = STATUS_STYLES[displayStatus] ?? STATUS_STYLES.given;
                                    const StatusIcon = style.icon;
                                    const catColor = CATEGORY_COLORS[cat] ?? 'bg-slate-50 border-slate-200';
                                    return (
                                        <Card key={c.id} className={`overflow-hidden border ${catColor}`}>
                                            <CardContent className="p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex items-start gap-3">
                                                        <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${style.bg}`}>
                                                            <StatusIcon className="h-4 w-4" />
                                                        </div>
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-semibold">{c.consent_type ?? c.consent_type_name ?? 'Consent'}</span>
                                                                <Badge className={`border-0 text-[10px] capitalize ${style.bg}`}>{displayStatus}</Badge>
                                                                {c.capacity_assessed && <Badge className="border-0 bg-purple-100 text-purple-700 text-[10px]">Capacity Assessed</Badge>}
                                                                {c.is_expiring_soon && !c.is_expired && <Badge className="border-0 bg-amber-100 text-amber-700 text-[10px] animate-pulse">Expiring Soon</Badge>}
                                                            </div>
                                                            <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                                {c.given_at && <span>Given: {new Date(c.given_at).toLocaleDateString('en-NZ')}</span>}
                                                                {c.given_method && <span>Method: {c.given_method}</span>}
                                                                {c.expires_at && (
                                                                    <span className={c.is_expired ? 'font-medium text-red-600' : c.is_expiring_soon ? 'font-medium text-amber-600' : ''}>
                                                                        Expires: {new Date(c.expires_at).toLocaleDateString('en-NZ')}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {c.conditions && <p className="mt-1.5 text-xs text-slate-600">{typeof c.conditions === 'string' ? c.conditions : JSON.stringify(c.conditions)}</p>}
                                                            {c.withdrawal_reason && <p className="mt-1.5 text-xs text-red-600">Withdrawn: {c.withdrawal_reason}</p>}
                                                        </div>
                                                    </div>
                                                    {c.status === 'given' && !c.is_expired && (
                                                        <Button variant="outline" size="sm" className="h-7 text-xs text-red-600 border-red-200 hover:bg-red-50"
                                                            onClick={() => { setShowWithdraw(c.id); setWithdrawReason(''); }}>
                                                            Withdraw
                                                        </Button>
                                                    )}
                                                </div>
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>
                        </div>
                    ))
                )}
            </PageShell>

            {/* Record Consent Dialog */}
            <Dialog open={showRecord} onOpenChange={setShowRecord}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Record Consent</DialogTitle>
                        <DialogDescription>Record a new consent for {client.first_name}.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Consent Type *</Label>
                            <Select value={formData.consent_type_id} onValueChange={(v) => setFormData({ ...formData, consent_type_id: v })}>
                                <SelectTrigger><SelectValue placeholder="Select type..." /></SelectTrigger>
                                <SelectContent>
                                    {consent_types.map((t: any) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name} {t.is_mandatory && <span className="text-red-500">*</span>}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Status *</Label>
                                <Select value={formData.status} onValueChange={(v) => setFormData({ ...formData, status: v })}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="given">Given</SelectItem>
                                        <SelectItem value="refused">Refused</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Method *</Label>
                                <Select value={formData.given_method} onValueChange={(v) => setFormData({ ...formData, given_method: v })}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="written">Written</SelectItem>
                                        <SelectItem value="verbal">Verbal</SelectItem>
                                        <SelectItem value="electronic">Electronic</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Date Given *</Label>
                                <Input type="date" value={formData.given_at} onChange={(e) => setFormData({ ...formData, given_at: e.target.value })} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input type="date" value={formData.expires_at} onChange={(e) => setFormData({ ...formData, expires_at: e.target.value })} />
                            </div>
                        </div>
                        {formData.status === 'refused' && (
                            <div className="space-y-1.5">
                                <Label>Reason for Refusal</Label>
                                <Textarea value={formData.refusal_reason} onChange={(e) => setFormData({ ...formData, refusal_reason: e.target.value })} placeholder="Document why consent was refused..." />
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea value={formData.given_notes} onChange={(e) => setFormData({ ...formData, given_notes: e.target.value })} placeholder="Additional notes..." className="min-h-[60px]" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowRecord(false)}>Cancel</Button>
                        <Button className="bg-violet-600 hover:bg-violet-700" onClick={submitConsent} disabled={!formData.consent_type_id}>Record Consent</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Withdraw Dialog */}
            <Dialog open={!!showWithdraw} onOpenChange={(open) => !open && setShowWithdraw(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Withdraw Consent</DialogTitle>
                        <DialogDescription>This action will mark the consent as withdrawn. Please provide a reason.</DialogDescription>
                    </DialogHeader>
                    <div className="py-2">
                        <Label>Reason for Withdrawal *</Label>
                        <Textarea value={withdrawReason} onChange={(e) => setWithdrawReason(e.target.value)} placeholder="Explain why consent is being withdrawn..." className="mt-1.5 min-h-[80px]" />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowWithdraw(null)}>Cancel</Button>
                        <Button variant="destructive" onClick={submitWithdraw} disabled={!withdrawReason.trim()}>Withdraw Consent</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
