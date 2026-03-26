import ClientAllergyBanner from '@/components/emar/ClientAllergyBanner';
import DrugInteractionAlert from '@/components/emar/DrugInteractionAlert';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import RecordAdministrationDialog from '@/components/medications/RecordAdministrationDialog';
import { type SafetyCheck } from '@/components/medications/SafetyCheckPanel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    MinusCircle,
    FileDown,
    Pill,
    Plus,
    Shield,
    Syringe,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

type Administration = {
    id: number;
    scheduled_for: string | null;
    administered_at: string | null;
    status: string;
    administered_by: string | null;
    witnessed_by: string | null;
    notes: string | null;
    reason: string | null;
};

type ScheduledMed = {
    id: number;
    name: string;
    dosage: string;
    frequency: string;
    route: string | null;
    form: string | null;
    instructions: string | null;
    controlled_drug: boolean;
    high_risk: boolean;
    witness_required: boolean;
    dose_times: string[];
    administrations: Administration[];
};

type PrnMed = {
    id: number;
    name: string;
    dosage: string;
    indication: string | null;
    max_per_day: string | null;
    prn_count_24h: number;
    prn_remaining: number | null;
    controlled_drug: boolean;
    administrations: Administration[];
};

type MarData = {
    scheduled: ScheduledMed[];
    prn: PrnMed[];
    stats: {
        total_scheduled: number;
        total_prn: number;
        given: number;
        refused: number;
        withheld: number;
        missed: number;
        pending: number;
    };
};

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    date_of_birth: string | null;
    nhi_number: string | null;
    active_medications_count: number;
};

type Allergy = {
    allergen: string;
    reaction: string;
    severity: string;
};

type Interaction = {
    drug_a: string;
    drug_b: string;
    severity: string;
    description: string;
};

type Props = {
    clients: Client[];
    selectedClient: Client | null;
    marData: MarData;
    date: string;
    staff: { id: number; name: string }[];
    allergies: Allergy[];
    interactions: Interaction[];
};

function statusIcon(status: string) {
    switch (status) {
        case 'given':
            return <Check className="h-4 w-4 text-green-600" />;
        case 'refused':
            return <XCircle className="h-4 w-4 text-orange-500" />;
        case 'withheld':
            return <MinusCircle className="h-4 w-4 text-amber-500" />;
        case 'missed':
            return <AlertTriangle className="h-4 w-4 text-red-500" />;
        case 'pending':
            return <Clock className="h-4 w-4 text-muted-foreground" />;
        default:
            return <Clock className="h-4 w-4 text-muted-foreground" />;
    }
}

function statusBadge(status: string) {
    const variant = {
        given: 'default' as const,
        refused: 'destructive' as const,
        withheld: 'secondary' as const,
        missed: 'destructive' as const,
        pending: 'outline' as const,
    }[status] ?? 'outline' as const;
    return <Badge variant={variant} className="text-xs">{status}</Badge>;
}

export default function MarCharts({ clients, selectedClient, marData, date, staff, allergies, interactions }: Props) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [selectedClientId, setSelectedClientId] = useState<string>(selectedClient?.id?.toString() ?? '');
    const [selectedMed, setSelectedMed] = useState<(ScheduledMed | PrnMed) | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [safetyCheck, setSafetyCheck] = useState<SafetyCheck | null>(null);
    const [prnHistoryData, setPrnHistoryData] = useState<{ history: Array<{ id: number; administered_at: string; dose_given?: string; reason?: string; administered_by?: string }>; count: number; max_per_day?: string; remaining_today?: number } | null>(null);
    const [loadingSafety, setLoadingSafety] = useState(false);
    const [selectedIsPrn, setSelectedIsPrn] = useState(false);

    function navigateDate(offset: number) {
        const d = new Date(date);
        d.setDate(d.getDate() + offset);
        router.get('/emar/mar', { client_id: selectedClientId, date: d.toISOString().split('T')[0] }, { preserveState: true });
    }

    function selectClient(id: string) {
        setSelectedClientId(id);
        router.get('/emar/mar', { client_id: id, date }, { preserveState: true });
    }

    async function openRecordDialog(med: ScheduledMed | PrnMed, isPrn: boolean) {
        if (!selectedClient) return;
        setLoadingSafety(true);
        setSelectedMed(med);
        setSelectedIsPrn(isPrn);
        setPrnHistoryData(null);
        setSafetyCheck(null);
        setDialogOpen(true);

        try {
            const response = await axios.get(`/api/medications/clients/${selectedClient.id}/medications/${med.id}/safety-check`);
            setSafetyCheck(response.data.safety_check ?? response.data);
            if (isPrn && response.data.prn_data) {
                setPrnHistoryData(response.data.prn_data);
            }
        } catch (error: unknown) {
            const message = error instanceof Error ? error.message : 'Failed to load safety check data';
            alert(message);
        } finally {
            setLoadingSafety(false);
        }
    }

    async function handleSubmit(data: Record<string, unknown>) {
        if (!selectedClient || !selectedMed) return;

        try {
            await axios.post(`/api/medications/clients/${selectedClient.id}/medications/${selectedMed.id}/administrations`, data);
            setDialogOpen(false);
            setSelectedMed(null);
            router.reload();
        } catch (error: unknown) {
            const err = error as { response?: { data?: { message?: string } } };
            const message = err?.response?.data?.message ?? 'Failed to record administration';
            alert(message);
        }
    }

    function hasPendingDoses(med: ScheduledMed): boolean {
        const recordedTimes = med.administrations
            .filter((a) => a.status !== 'pending')
            .map((a) => a.scheduled_for);
        return med.dose_times.some((t) => !recordedTimes.includes(t));
    }

    const mappedMedication = selectedMed
        ? {
              id: selectedMed.id,
              name: selectedMed.name,
              dosage: selectedMed.dosage,
              route: 'route' in selectedMed ? selectedMed.route ?? undefined : undefined,
              form: 'form' in selectedMed ? selectedMed.form ?? undefined : undefined,
              is_prn: selectedIsPrn,
              controlled_drug: selectedMed.controlled_drug,
              high_risk: 'high_risk' in selectedMed ? selectedMed.high_risk : false,
              witness_required: 'witness_required' in selectedMed ? selectedMed.witness_required : false,
              instructions: 'instructions' in selectedMed ? selectedMed.instructions ?? undefined : undefined,
          }
        : null;

    return (
        <AppLayout>
            <Head title="eMAR - MAR Charts" />
            <PageHeader title="MAR Charts" description="Medication Administration Record charts by client and date." backHref="/emar" />
            <PageShell>
                {/* Filters */}
                <div className="mb-6 flex flex-wrap items-center gap-4">
                    <div className="w-72">
                        <Select value={selectedClientId} onValueChange={selectClient}>
                            <SelectTrigger><SelectValue placeholder="Select client..." /></SelectTrigger>
                            <SelectContent>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={c.id.toString()}>
                                        {c.last_name}, {c.first_name} ({c.active_medications_count} meds)
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="icon" onClick={() => navigateDate(-1)}><ChevronLeft className="h-4 w-4" /></Button>
                        <Input type="date" value={date} onChange={(e) => router.get('/emar/mar', { client_id: selectedClientId, date: e.target.value }, { preserveState: true })} className="w-40" />
                        <Button variant="outline" size="icon" onClick={() => navigateDate(1)}><ChevronRight className="h-4 w-4" /></Button>
                        <Button variant="outline" size="sm" onClick={() => router.get('/emar/mar', { client_id: selectedClientId, date: new Date().toISOString().split('T')[0] }, { preserveState: true })}>Today</Button>
                    </div>
                    {selectedClientId && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.open(`/emar/pdf/mar-chart?client_id=${selectedClientId}&date_from=${date}&date_to=${date}`, '_blank')}
                        >
                            <FileDown className="mr-1 h-4 w-4" />
                            Print PDF
                        </Button>
                    )}
                </div>

                {!selectedClient ? (
                    <Card>
                        <CardContent className="flex flex-col items-center py-16">
                            <Pill className="mb-4 h-12 w-12 text-muted-foreground/30" />
                            <p className="text-muted-foreground">Select a client to view their MAR chart.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Client Header & Stats */}
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold">{selectedClient.last_name}, {selectedClient.first_name}</h2>
                                {selectedClient.nhi_number && <p className="text-sm text-muted-foreground">NHI: {selectedClient.nhi_number}</p>}
                            </div>
                            {marData?.stats && (
                                <div className="flex gap-3">
                                    <Badge variant="outline" className="gap-1"><Check className="h-3 w-3 text-green-600" /> {marData.stats.given} Given</Badge>
                                    <Badge variant="outline" className="gap-1"><XCircle className="h-3 w-3 text-orange-500" /> {marData.stats.refused} Refused</Badge>
                                    <Badge variant="outline" className="gap-1"><MinusCircle className="h-3 w-3 text-amber-500" /> {marData.stats.withheld} Withheld</Badge>
                                    <Badge variant="outline" className="gap-1"><AlertTriangle className="h-3 w-3 text-red-500" /> {marData.stats.missed} Missed</Badge>
                                    <Badge variant="outline" className="gap-1"><Clock className="h-3 w-3" /> {marData.stats.pending} Pending</Badge>
                                </div>
                            )}
                        </div>

                        {/* Allergy & Interaction Warnings */}
                        {allergies && allergies.length > 0 && (
                            <div className="mb-4">
                                <ClientAllergyBanner allergies={allergies} />
                            </div>
                        )}
                        {interactions && interactions.length > 0 && (
                            <div className="mb-4">
                                <DrugInteractionAlert interactions={interactions} />
                            </div>
                        )}

                        {/* Scheduled Medications */}
                        <Card className="mb-6">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Scheduled Medications ({marData?.scheduled?.length ?? 0})</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">Medication</th>
                                                <th className="p-3 text-left font-medium">Dose</th>
                                                <th className="p-3 text-left font-medium">Route</th>
                                                <th className="p-3 text-left font-medium">Frequency</th>
                                                <th className="p-3 text-left font-medium">Flags</th>
                                                <th className="p-3 text-left font-medium">Administrations</th>
                                                <th className="p-3 text-left font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(marData?.scheduled ?? []).map((med) => (
                                                <tr key={med.id} className="border-b last:border-0">
                                                    <td className="p-3">
                                                        <span className="font-medium">{med.name}</span>
                                                        {med.instructions && <p className="mt-0.5 text-xs text-muted-foreground">{med.instructions}</p>}
                                                    </td>
                                                    <td className="p-3">{med.dosage}</td>
                                                    <td className="p-3">{med.route ?? '—'}</td>
                                                    <td className="p-3">{med.frequency}</td>
                                                    <td className="p-3">
                                                        <div className="flex gap-1">
                                                            {med.controlled_drug && (
                                                                <TooltipProvider><Tooltip><TooltipTrigger><Shield className="h-4 w-4 text-red-500" /></TooltipTrigger><TooltipContent>Controlled Drug</TooltipContent></Tooltip></TooltipProvider>
                                                            )}
                                                            {med.high_risk && (
                                                                <TooltipProvider><Tooltip><TooltipTrigger><AlertTriangle className="h-4 w-4 text-amber-500" /></TooltipTrigger><TooltipContent>High Risk</TooltipContent></Tooltip></TooltipProvider>
                                                            )}
                                                            {med.witness_required && (
                                                                <TooltipProvider><Tooltip><TooltipTrigger><Eye className="h-4 w-4 text-blue-500" /></TooltipTrigger><TooltipContent>Witness Required</TooltipContent></Tooltip></TooltipProvider>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {med.administrations.length > 0 ? med.administrations.map((a, idx) => (
                                                                <TooltipProvider key={a.id ?? `slot-${idx}`}>
                                                                    <Tooltip>
                                                                        <TooltipTrigger>
                                                                            <div className={`flex items-center gap-1 rounded-md border px-2 py-1 ${
                                                                                a.status === 'given' ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950/30' :
                                                                                a.status === 'missed' ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30' :
                                                                                a.status === 'refused' ? 'border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950/30' :
                                                                                a.status === 'withheld' ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30' :
                                                                                'border-muted bg-muted/30'
                                                                            }`}>
                                                                                {statusIcon(a.status)}
                                                                                <span className="text-xs font-mono">
                                                                                    {a.scheduled_for ? new Date(a.scheduled_for).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                                                </span>
                                                                            </div>
                                                                        </TooltipTrigger>
                                                                        <TooltipContent>
                                                                            <p className="font-medium capitalize">{a.status}</p>
                                                                            {a.administered_by && <p>By: {a.administered_by}</p>}
                                                                            {a.witnessed_by && <p>Witnessed: {a.witnessed_by}</p>}
                                                                            {a.reason && <p>Reason: {a.reason}</p>}
                                                                            {a.notes && <p>Notes: {a.notes}</p>}
                                                                        </TooltipContent>
                                                                    </Tooltip>
                                                                </TooltipProvider>
                                                            )) : (
                                                                med.dose_times.length > 0 ? (
                                                                    med.dose_times.map((t) => (
                                                                        <div key={t} className="flex items-center gap-1 rounded-md border border-muted bg-muted/30 px-2 py-1">
                                                                            <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                                                            <span className="text-xs font-mono text-muted-foreground">{t}</span>
                                                                        </div>
                                                                    ))
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">No schedule</span>
                                                                )
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={!hasPendingDoses(med)}
                                                            onClick={() => openRecordDialog(med, false)}
                                                        >
                                                            <Syringe className="mr-1 h-3 w-3" />
                                                            Record
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                            {(marData?.scheduled ?? []).length === 0 && (
                                                <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No scheduled medications.</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* PRN Medications */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">PRN / As-Needed Medications ({marData?.prn?.length ?? 0})</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">Medication</th>
                                                <th className="p-3 text-left font-medium">Dose</th>
                                                <th className="p-3 text-left font-medium">Indication</th>
                                                <th className="p-3 text-left font-medium">24h Usage</th>
                                                <th className="p-3 text-left font-medium">Administrations</th>
                                                <th className="p-3 text-left font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(marData?.prn ?? []).map((med) => (
                                                <tr key={med.id} className="border-b last:border-0">
                                                    <td className="p-3">
                                                        <span className="font-medium">{med.name}</span>
                                                        {med.controlled_drug && <Badge variant="destructive" className="ml-2 text-[10px]">CD</Badge>}
                                                    </td>
                                                    <td className="p-3">{med.dosage}</td>
                                                    <td className="p-3 text-xs">{med.indication ?? '—'}</td>
                                                    <td className="p-3">
                                                        <div className="flex items-center gap-2">
                                                            <span className={`text-sm font-medium ${med.prn_remaining === 0 ? 'text-red-600' : med.prn_remaining !== null && med.prn_remaining <= 1 ? 'text-amber-600' : ''}`}>
                                                                {med.prn_count_24h} / {med.max_per_day ?? '∞'}
                                                            </span>
                                                            {med.prn_remaining !== null && (
                                                                <span className="text-xs text-muted-foreground">({med.prn_remaining} remaining)</span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            {med.administrations.map((a) => (
                                                                <div key={a.id} className="flex items-center gap-1">
                                                                    {statusIcon(a.status)}
                                                                    <span className="text-xs">
                                                                        {a.administered_at ? new Date(a.administered_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                                    </span>
                                                                </div>
                                                            ))}
                                                            {med.administrations.length === 0 && <span className="text-xs text-muted-foreground">None today</span>}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={med.prn_remaining === 0}
                                                            onClick={() => openRecordDialog(med, true)}
                                                        >
                                                            <Plus className="mr-1 h-3 w-3" />
                                                            Give
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                            {(marData?.prn ?? []).length === 0 && (
                                                <tr><td colSpan={6} className="p-6 text-center text-muted-foreground">No PRN medications.</td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        <RecordAdministrationDialog
                            isOpen={dialogOpen}
                            onClose={() => { setDialogOpen(false); setSelectedMed(null); }}
                            onSubmit={handleSubmit}
                            medication={mappedMedication}
                            witnesses={staff.filter((s) => s.id !== auth.user.id)}
                            currentUserId={auth.user.id}
                            safetyCheck={safetyCheck}
                            prnData={prnHistoryData}
                            isLoading={loadingSafety}
                        />
                    </>
                )}
            </PageShell>
        </AppLayout>
    );
}
