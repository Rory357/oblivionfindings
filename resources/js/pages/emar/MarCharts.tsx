import ClientAllergyBanner from '@/components/emar/ClientAllergyBanner';
import DrugInteractionAlert from '@/components/emar/DrugInteractionAlert';
import AdministrationEvidenceDialog from '@/components/medications/AdministrationEvidenceDialog';
import RecordAdministrationDialog from '@/components/medications/RecordAdministrationDialog';
import RefusalFollowUpDialog from '@/components/medications/RefusalFollowUpDialog';
import { type SafetyCheck } from '@/components/medications/SafetyCheckPanel';
import FleetHero from '@/components/fleet-hero';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { submitEmarMutation } from '@/lib/emar-offline';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    FileDown,
    MinusCircle,
    Paperclip,
    Phone,
    Pill,
    Plus,
    Shield,
    Syringe,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type AdministrationAttachment = {
    id: number;
    file_name: string;
    mime_type?: string | null;
    file_size?: number | null;
    formatted_size?: string | null;
    description?: string | null;
    uploaded_at?: string | null;
    uploaded_by?: string | null;
    download_url: string;
    can_delete: boolean;
};

type Administration = {
    id: number | null;
    scheduled_for: string | null;
    administered_at: string | null;
    status: string;
    administered_by: string | null;
    witnessed_by: string | null;
    notes: string | null;
    reason: string | null;
    dose_given?: string | null;
    outcome?: string | null;
    site?: string | null;
    created_at?: string | null;
    is_correction?: boolean;
    correction_reason?: string | null;
    correction_status?: string | null;
    correction_rejection_reason?: string | null;
    attachments?: AdministrationAttachment[];
};

type MedicationScanVerification = {
    primary_code: string;
    primary_label: string;
    primary_source: string;
    internal_code: string;
    vendor_barcode?: string | null;
    nzulm_code?: string | null;
    requires_internal_code: boolean;
    svg_url: string;
    code_options: Array<{
        source: string;
        label: string;
        value: string;
    }>;
};

type MedicationStock = {
    on_hand: number;
    unit: string;
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
    scan_verification: MedicationScanVerification;
    stock: MedicationStock | null;
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
    high_risk: boolean;
    witness_required: boolean;
    administrations: Administration[];
    scan_verification: MedicationScanVerification;
    stock: MedicationStock | null;
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
    id: number;
    allergen: string;
    reaction: string;
    severity: string;
    notes?: string | null;
    identified_date?: string | null;
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
    clientContext: {
        profile: {
            gp_name?: string | null;
            gp_practice?: string | null;
            gp_phone?: string | null;
            hospital_preference?: string | null;
            medical_history?: string | null;
            notes?: string | null;
        } | null;
        conditions: Array<{
            id: number;
            label: string;
            severity?: string | null;
            notes?: string | null;
        }>;
        emergency_contacts: Array<{
            id: number;
            name: string;
            relationship?: string | null;
            phone?: string | null;
            email?: string | null;
            preferred_method?: string | null;
            availability?: string | null;
        }>;
        medication_charts: Array<{
            id: number;
            title?: string | null;
            original_name?: string | null;
            version?: string | null;
            effective_date?: string | null;
            expiry_date?: string | null;
            notes?: string | null;
            uploaded_at?: string | null;
            uploaded_by?: string | null;
            download_url: string;
        }>;
    } | null;
    breakGlassAccess: {
        active: boolean;
        accesses: Array<{
            id: number;
            user_id: number;
            user_name?: string | null;
            reason?: string | null;
            expires_at?: string | null;
            is_current_user: boolean;
        }>;
    };
    pendingCorrections: Array<{
        id: number;
        original_administration_id?: number | null;
        medication_name: string;
        status: string;
        dose_given?: string | null;
        reason?: string | null;
        notes?: string | null;
        correction_reason?: string | null;
        submitted_by?: string | null;
        submitted_at?: string | null;
        administered_at?: string | null;
        attachments?: AdministrationAttachment[];
    }>;
    alerts: Array<{
        id: number;
        alert_type: string;
        severity: string;
        message: string;
        created_at?: string | null;
    }>;
    controlledDiscrepancies: Array<{
        id: number;
        medication_name?: string | null;
        difference?: number | null;
        reason?: string | null;
        notes?: string | null;
        status: string;
        reported_at?: string | null;
    }>;
    can: {
        record: boolean;
        correct: boolean;
        revoke_break_glass: boolean;
        view_controlled: boolean;
        export_reports: boolean;
    };
};

function statusIcon(status: string) {
    switch (status) {
        case 'given':
            return <Check className="h-4 w-4 text-status-success" />;
        case 'refused':
            return <XCircle className="h-4 w-4 text-status-warning" />;
        case 'withheld':
            return <MinusCircle className="h-4 w-4 text-status-warning" />;
        case 'missed':
            return <AlertTriangle className="h-4 w-4 text-status-critical" />;
        case 'pending':
            return <Clock className="h-4 w-4 text-muted-foreground" />;
        default:
            return <Clock className="h-4 w-4 text-muted-foreground" />;
    }
}

function statusBadge(status: string) {
    const variant =
        {
            given: 'default' as const,
            refused: 'destructive' as const,
            withheld: 'secondary' as const,
            missed: 'destructive' as const,
            pending: 'outline' as const,
        }[status] ?? ('outline' as const);
    return (
        <Badge variant={variant} className="text-xs">
            {status}
        </Badge>
    );
}

export default function MarCharts({
    clients,
    selectedClient,
    marData,
    date,
    staff,
    allergies,
    interactions,
    clientContext,
    breakGlassAccess,
    pendingCorrections,
    alerts,
    controlledDiscrepancies,
    can,
}: Props) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const [selectedClientId, setSelectedClientId] = useState<string>(
        selectedClient?.id?.toString() ?? '',
    );
    const [selectedMed, setSelectedMed] = useState<
        (ScheduledMed | PrnMed) | null
    >(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [safetyCheck, setSafetyCheck] = useState<SafetyCheck | null>(null);
    const [prnHistoryData, setPrnHistoryData] = useState<{
        history: Array<{
            id: number;
            administered_at: string;
            dose_given?: string;
            reason?: string;
            administered_by?: string;
        }>;
        count: number;
        max_per_day?: string;
        remaining_today?: number;
    } | null>(null);
    const [loadingSafety, setLoadingSafety] = useState(false);
    const [selectedIsPrn, setSelectedIsPrn] = useState(false);
    const [selectedScheduledTime, setSelectedScheduledTime] = useState<
        string | null
    >(null);
    const [followUpTarget, setFollowUpTarget] = useState<{
        administrationId: number;
        medicationName: string;
    } | null>(null);
    const [evidenceTarget, setEvidenceTarget] = useState<{
        administration: Administration;
        medicationName: string;
    } | null>(null);
    const [attachmentOverrides, setAttachmentOverrides] = useState<
        Record<number, AdministrationAttachment[]>
    >({});

    function navigateDate(offset: number) {
        const d = new Date(date);
        d.setDate(d.getDate() + offset);
        router.get(
            '/emar/mar',
            {
                client_id: selectedClientId,
                date: d.toISOString().split('T')[0],
            },
            { preserveState: true },
        );
    }

    function selectClient(id: string) {
        setSelectedClientId(id);
        router.get(
            '/emar/mar',
            { client_id: id, date },
            { preserveState: true },
        );
    }

    function getNextRecordableAdministration(med: ScheduledMed) {
        return (
            [...med.administrations]
                .filter(
                    (administration) =>
                        ['pending', 'missed'].includes(administration.status) &&
                        administration.scheduled_for,
                )
                .sort((left, right) =>
                    (left.scheduled_for ?? '').localeCompare(
                        right.scheduled_for ?? '',
                    ),
                )[0] ?? null
        );
    }

    function getLatestRefusalFollowUpTarget(med: ScheduledMed | PrnMed) {
        return (
            [...med.administrations]
                .filter(
                    (administration) =>
                        typeof administration.id === 'number' &&
                        ['refused', 'withheld'].includes(administration.status),
                )
                .sort((left, right) =>
                    (
                        right.administered_at ??
                        right.scheduled_for ??
                        ''
                    ).localeCompare(
                        left.administered_at ?? left.scheduled_for ?? '',
                    ),
                )[0] ?? null
        );
    }

    async function openRecordDialog(
        med: ScheduledMed | PrnMed,
        isPrn: boolean,
    ) {
        if (!selectedClient) return;
        setLoadingSafety(true);
        setSelectedMed(med);
        setSelectedIsPrn(isPrn);
        setSelectedScheduledTime(
            !isPrn && 'dose_times' in med
                ? (getNextRecordableAdministration(med)?.scheduled_for ?? null)
                : null,
        );
        setPrnHistoryData(null);
        setSafetyCheck(null);
        setDialogOpen(true);

        try {
            const response = await axios.get(
                `/api/medications/clients/${selectedClient.id}/medications/${med.id}/safety-check`,
            );
            setSafetyCheck(response.data.safety_check ?? response.data);
            if (isPrn && response.data.prn_data) {
                setPrnHistoryData(response.data.prn_data);
            }
        } catch (error: unknown) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to load safety check data',
            );
        } finally {
            setLoadingSafety(false);
        }
    }

    async function handleSubmit(data: Record<string, unknown>) {
        if (!selectedClient || !selectedMed) return;

        try {
            const result = await submitEmarMutation(
                `/api/medications/clients/${selectedClient.id}/medications/${selectedMed.id}/administrations`,
                data,
                {
                    successMessage: 'Medication administration recorded.',
                    queuedMessage:
                        'Medication administration saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            setDialogOpen(false);
            setSelectedMed(null);
            setSelectedScheduledTime(null);

            if (result.status !== 'queued') {
                router.reload();
            }
        } catch (error: unknown) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to record administration',
            );
        }
    }

    function getAdministrationAttachments(
        administration: Administration,
    ): AdministrationAttachment[] {
        if (!administration.id) {
            return [];
        }

        return (
            attachmentOverrides[administration.id] ??
            administration.attachments ??
            []
        );
    }

    function handleAttachmentsChange(
        administrationId: number,
        attachments: AdministrationAttachment[],
    ) {
        setAttachmentOverrides((current) => ({
            ...current,
            [administrationId]: attachments,
        }));
    }

    function hasPendingDoses(med: ScheduledMed): boolean {
        return med.administrations.some((administration) =>
            ['pending', 'missed'].includes(administration.status),
        );
    }

    function handleApproveCorrection(correctionId: number) {
        router.post(
            `/emar/corrections/${correctionId}/approve`,
            {},
            { preserveScroll: true },
        );
    }

    function handleRejectCorrection(correctionId: number) {
        const reason = window.prompt('Reason for rejecting this correction:');
        if (reason === null || !reason.trim()) {
            return;
        }

        router.post(
            `/emar/corrections/${correctionId}/reject`,
            { reason },
            { preserveScroll: true },
        );
    }

    function handleRevokeBreakGlass(accessId: number) {
        if (!selectedClient) {
            return;
        }

        router.delete(
            `/emar/clients/${selectedClient.id}/break-glass/${accessId}`,
            {
                preserveScroll: true,
            },
        );
    }

    const mappedMedication = selectedMed
        ? {
              id: selectedMed.id,
              name: selectedMed.name,
              dosage: selectedMed.dosage,
              route:
                  'route' in selectedMed
                      ? (selectedMed.route ?? undefined)
                      : undefined,
              form:
                  'form' in selectedMed
                      ? (selectedMed.form ?? undefined)
                      : undefined,
              is_prn: selectedIsPrn,
              controlled_drug: selectedMed.controlled_drug,
              high_risk:
                  'high_risk' in selectedMed ? selectedMed.high_risk : false,
              witness_required:
                  'witness_required' in selectedMed
                      ? selectedMed.witness_required
                      : false,
              instructions:
                  'instructions' in selectedMed
                      ? (selectedMed.instructions ?? undefined)
                      : undefined,
              scan_verification: selectedMed.scan_verification,
              stock: 'stock' in selectedMed ? selectedMed.stock : null,
          }
        : null;

    return (
        <AppLayout>
            <Head title="eMAR - MAR Charts" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="MAR Charts"
                    description="Medication Administration Record charts by client and date"
                    icon={<Pill className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Filters */}
                <div className="mb-6 flex flex-wrap items-center gap-4">
                    <div className="w-72">
                        <Select
                            value={selectedClientId}
                            onValueChange={selectClient}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select client..." />
                            </SelectTrigger>
                            <SelectContent>
                                {clients.map((c) => (
                                    <SelectItem
                                        key={c.id}
                                        value={c.id.toString()}
                                    >
                                        {c.last_name}, {c.first_name} (
                                        {c.active_medications_count} meds)
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={() => navigateDate(-1)}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <Input
                            type="date"
                            value={date}
                            onChange={(e) =>
                                router.get(
                                    '/emar/mar',
                                    {
                                        client_id: selectedClientId,
                                        date: e.target.value,
                                    },
                                    { preserveState: true },
                                )
                            }
                            className="w-40"
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={() => navigateDate(1)}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.get(
                                    '/emar/mar',
                                    {
                                        client_id: selectedClientId,
                                        date: new Date()
                                            .toISOString()
                                            .split('T')[0],
                                    },
                                    { preserveState: true },
                                )
                            }
                        >
                            Today
                        </Button>
                    </div>
                    {selectedClientId && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!can.export_reports}
                            onClick={() =>
                                window.open(
                                    `/emar/pdf/mar-chart?client_id=${selectedClientId}&date_from=${date}&date_to=${date}`,
                                    '_blank',
                                )
                            }
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
                            <p className="text-muted-foreground">
                                Select a client to view their MAR chart.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Client Header & Stats */}
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    {selectedClient.last_name},{' '}
                                    {selectedClient.first_name}
                                </h2>
                                {selectedClient.nhi_number && (
                                    <p className="text-sm text-muted-foreground">
                                        NHI: {selectedClient.nhi_number}
                                    </p>
                                )}
                            </div>
                            {marData?.stats && (
                                <div className="flex gap-3">
                                    <Badge variant="outline" className="gap-1">
                                        <Check className="h-3 w-3 text-status-success" />{' '}
                                        {marData.stats.given} Given
                                    </Badge>
                                    <Badge variant="outline" className="gap-1">
                                        <XCircle className="h-3 w-3 text-status-warning" />{' '}
                                        {marData.stats.refused} Refused
                                    </Badge>
                                    <Badge variant="outline" className="gap-1">
                                        <MinusCircle className="h-3 w-3 text-status-warning" />{' '}
                                        {marData.stats.withheld} Withheld
                                    </Badge>
                                    <Badge variant="outline" className="gap-1">
                                        <AlertTriangle className="h-3 w-3 text-status-critical" />{' '}
                                        {marData.stats.missed} Missed
                                    </Badge>
                                    <Badge variant="outline" className="gap-1">
                                        <Clock className="h-3 w-3" />{' '}
                                        {marData.stats.pending} Pending
                                    </Badge>
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
                                <DrugInteractionAlert
                                    interactions={interactions}
                                />
                            </div>
                        )}

                        <div className="mb-6 grid gap-4 xl:grid-cols-2">
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Clinical Context
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div>
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            GP
                                        </div>
                                        <div>
                                            {clientContext?.profile?.gp_name ??
                                                'Not recorded'}
                                        </div>
                                        {clientContext?.profile
                                            ?.gp_practice && (
                                            <div className="text-muted-foreground">
                                                {
                                                    clientContext.profile
                                                        .gp_practice
                                                }
                                            </div>
                                        )}
                                        {clientContext?.profile?.gp_phone && (
                                            <div className="text-muted-foreground">
                                                {clientContext.profile.gp_phone}
                                            </div>
                                        )}
                                    </div>

                                    <div>
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Conditions
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {clientContext?.conditions
                                                ?.length ? (
                                                clientContext.conditions.map(
                                                    (condition) => (
                                                        <Badge
                                                            key={condition.id}
                                                            variant="outline"
                                                        >
                                                            {condition.label}
                                                            {condition.severity
                                                                ? ` • ${condition.severity}`
                                                                : ''}
                                                        </Badge>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    No conditions recorded.
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div>
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Emergency Contacts
                                        </div>
                                        <div className="mt-2 space-y-2">
                                            {clientContext?.emergency_contacts
                                                ?.length ? (
                                                clientContext.emergency_contacts.map(
                                                    (contact) => (
                                                        <div
                                                            key={contact.id}
                                                            className="rounded-md border p-2"
                                                        >
                                                            <div className="font-medium">
                                                                {contact.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {contact.relationship ??
                                                                    'Relationship not recorded'}
                                                                {contact.phone
                                                                    ? ` • ${contact.phone}`
                                                                    : ''}
                                                            </div>
                                                            {contact.preferred_method && (
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    Preferred
                                                                    contact:{' '}
                                                                    {
                                                                        contact.preferred_method
                                                                    }
                                                                </div>
                                                            )}
                                                        </div>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    No emergency contacts
                                                    recorded.
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div>
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Medication Charts
                                        </div>
                                        <div className="mt-2 space-y-2">
                                            {clientContext?.medication_charts
                                                ?.length ? (
                                                clientContext.medication_charts.map(
                                                    (chart) => (
                                                        <a
                                                            key={chart.id}
                                                            href={
                                                                chart.download_url
                                                            }
                                                            className="block rounded-md border p-2 transition hover:border-primary/40 hover:bg-muted/40"
                                                        >
                                                            <div className="font-medium">
                                                                {chart.title ||
                                                                    chart.original_name ||
                                                                    `Chart ${chart.id}`}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {chart.version
                                                                    ? `Version ${chart.version}`
                                                                    : 'Medication chart'}
                                                                {chart.effective_date
                                                                    ? ` • Effective ${chart.effective_date}`
                                                                    : ''}
                                                            </div>
                                                        </a>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    No medication charts
                                                    uploaded.
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Alerts & Workflow
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="space-y-2">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Active Alerts
                                        </div>
                                        {alerts.length > 0 ? (
                                            alerts.map((alert) => (
                                                <Alert
                                                    key={alert.id}
                                                    variant={
                                                        alert.severity ===
                                                        'critical'
                                                            ? 'destructive'
                                                            : 'default'
                                                    }
                                                >
                                                    <AlertTriangle className="h-4 w-4" />
                                                    <AlertTitle>
                                                        {alert.alert_type.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </AlertTitle>
                                                    <AlertDescription>
                                                        <p>{alert.message}</p>
                                                        {alert.created_at && (
                                                            <p className="text-xs">
                                                                Raised{' '}
                                                                {new Date(
                                                                    alert.created_at,
                                                                ).toLocaleString(
                                                                    'en-NZ',
                                                                )}
                                                            </p>
                                                        )}
                                                    </AlertDescription>
                                                </Alert>
                                            ))
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No active medication alerts.
                                            </span>
                                        )}
                                    </div>

                                    {can.view_controlled && (
                                        <div className="space-y-2">
                                            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Controlled Discrepancies
                                            </div>
                                            {controlledDiscrepancies.length >
                                            0 ? (
                                                controlledDiscrepancies.map(
                                                    (discrepancy) => (
                                                        <div
                                                            key={discrepancy.id}
                                                            className="rounded-md border p-3"
                                                        >
                                                            <div className="flex items-center justify-between gap-2">
                                                                <span className="font-medium">
                                                                    {discrepancy.medication_name ??
                                                                        'Controlled medication'}
                                                                </span>
                                                                <Badge variant="destructive">
                                                                    {
                                                                        discrepancy.status
                                                                    }
                                                                </Badge>
                                                            </div>
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                Difference:{' '}
                                                                {discrepancy.difference ??
                                                                    0}
                                                                {discrepancy.reported_at
                                                                    ? ` • Reported ${new Date(discrepancy.reported_at).toLocaleString('en-NZ')}`
                                                                    : ''}
                                                            </div>
                                                            {(discrepancy.reason ||
                                                                discrepancy.notes) && (
                                                                <p className="mt-2 text-xs text-muted-foreground">
                                                                    {discrepancy.reason ??
                                                                        discrepancy.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    No open controlled
                                                    discrepancies.
                                                </span>
                                            )}
                                        </div>
                                    )}

                                    <div className="space-y-2">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Break-Glass Access
                                        </div>
                                        {breakGlassAccess.active ? (
                                            breakGlassAccess.accesses.map(
                                                (access) => (
                                                    <div
                                                        key={access.id}
                                                        className="rounded-md border p-3"
                                                    >
                                                        <div className="flex items-center justify-between gap-2">
                                                            <div>
                                                                <div className="font-medium">
                                                                    {access.user_name ??
                                                                        'Unknown user'}
                                                                </div>
                                                                <div className="text-xs text-muted-foreground">
                                                                    Expires{' '}
                                                                    {access.expires_at
                                                                        ? new Date(
                                                                              access.expires_at,
                                                                          ).toLocaleString(
                                                                              'en-NZ',
                                                                          )
                                                                        : 'soon'}
                                                                </div>
                                                            </div>
                                                            {can.revoke_break_glass && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        handleRevokeBreakGlass(
                                                                            access.id,
                                                                        )
                                                                    }
                                                                >
                                                                    Revoke
                                                                </Button>
                                                            )}
                                                        </div>
                                                        {access.reason && (
                                                            <p className="mt-2 text-xs text-muted-foreground">
                                                                {access.reason}
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No active break-glass access
                                                recorded.
                                            </span>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Pending Corrections
                                        </div>
                                        {pendingCorrections.length > 0 ? (
                                            pendingCorrections.map(
                                                (correction) => (
                                                    <div
                                                        key={correction.id}
                                                        className="rounded-md border p-3"
                                                    >
                                                        <div className="flex items-center justify-between gap-3">
                                                            <div>
                                                                <div className="font-medium">
                                                                    {
                                                                        correction.medication_name
                                                                    }
                                                                </div>
                                                                <div className="text-xs text-muted-foreground">
                                                                    {
                                                                        correction.status
                                                                    }
                                                                    {correction.submitted_by
                                                                        ? ` • ${correction.submitted_by}`
                                                                        : ''}
                                                                </div>
                                                                {correction
                                                                    .attachments
                                                                    ?.length ? (
                                                                    <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                                        <Paperclip className="h-3 w-3" />
                                                                        {
                                                                            correction
                                                                                .attachments
                                                                                .length
                                                                        }{' '}
                                                                        evidence
                                                                        file(s)
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                            <div className="flex gap-2">
                                                                {(correction
                                                                    .attachments
                                                                    ?.length ||
                                                                    can.record ||
                                                                    can.correct) && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() =>
                                                                            setEvidenceTarget(
                                                                                {
                                                                                    administration:
                                                                                        {
                                                                                            id: correction.id,
                                                                                            scheduled_for:
                                                                                                null,
                                                                                            administered_at:
                                                                                                correction.administered_at ??
                                                                                                null,
                                                                                            status: correction.status,
                                                                                            administered_by:
                                                                                                correction.submitted_by ??
                                                                                                null,
                                                                                            witnessed_by:
                                                                                                null,
                                                                                            notes:
                                                                                                correction.notes ??
                                                                                                null,
                                                                                            reason:
                                                                                                correction.reason ??
                                                                                                null,
                                                                                            dose_given:
                                                                                                correction.dose_given ??
                                                                                                null,
                                                                                            attachments:
                                                                                                correction.attachments ??
                                                                                                [],
                                                                                        },
                                                                                    medicationName:
                                                                                        correction.medication_name,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <Paperclip className="mr-1 h-3.5 w-3.5" />
                                                                        Evidence
                                                                    </Button>
                                                                )}
                                                                {can.correct && (
                                                                    <div className="flex gap-2">
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                handleApproveCorrection(
                                                                                    correction.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            Approve
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() =>
                                                                                handleRejectCorrection(
                                                                                    correction.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            Reject
                                                                        </Button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                        {correction.correction_reason && (
                                                            <p className="mt-2 text-xs text-muted-foreground">
                                                                Correction
                                                                reason:{' '}
                                                                {
                                                                    correction.correction_reason
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No pending corrections awaiting
                                                review.
                                            </span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Scheduled Medications */}
                        <Card className="mb-6">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Scheduled Medications (
                                    {marData?.scheduled?.length ?? 0})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">
                                                    Medication
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Dose
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Route
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Frequency
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Flags
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Administrations
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(marData?.scheduled ?? []).map(
                                                (med) => (
                                                    <tr
                                                        key={med.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="p-3">
                                                            <span className="font-medium">
                                                                {med.name}
                                                            </span>
                                                            {med.instructions && (
                                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                                    {
                                                                        med.instructions
                                                                    }
                                                                </p>
                                                            )}
                                                            {med.stock && (
                                                                <p className="mt-1 text-xs text-muted-foreground">
                                                                    Stock:{' '}
                                                                    {
                                                                        med
                                                                            .stock
                                                                            .on_hand
                                                                    }{' '}
                                                                    {
                                                                        med
                                                                            .stock
                                                                            .unit
                                                                    }
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="p-3">
                                                            {med.dosage}
                                                        </td>
                                                        <td className="p-3">
                                                            {med.route ?? '—'}
                                                        </td>
                                                        <td className="p-3">
                                                            {med.frequency}
                                                        </td>
                                                        <td className="p-3">
                                                            <div className="flex gap-1">
                                                                {med.controlled_drug && (
                                                                    <TooltipProvider>
                                                                        <Tooltip>
                                                                            <TooltipTrigger>
                                                                                <Shield className="h-4 w-4 text-status-critical" />
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>
                                                                                Controlled
                                                                                Drug
                                                                            </TooltipContent>
                                                                        </Tooltip>
                                                                    </TooltipProvider>
                                                                )}
                                                                {med.high_risk && (
                                                                    <TooltipProvider>
                                                                        <Tooltip>
                                                                            <TooltipTrigger>
                                                                                <AlertTriangle className="h-4 w-4 text-status-warning" />
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>
                                                                                High
                                                                                Risk
                                                                            </TooltipContent>
                                                                        </Tooltip>
                                                                    </TooltipProvider>
                                                                )}
                                                                {med.witness_required && (
                                                                    <TooltipProvider>
                                                                        <Tooltip>
                                                                            <TooltipTrigger>
                                                                                <Eye className="h-4 w-4 text-status-info" />
                                                                            </TooltipTrigger>
                                                                            <TooltipContent>
                                                                                Witness
                                                                                Required
                                                                            </TooltipContent>
                                                                        </Tooltip>
                                                                    </TooltipProvider>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="p-3">
                                                            <div className="flex flex-wrap gap-1.5">
                                                                {med
                                                                    .administrations
                                                                    .length >
                                                                0 ? (
                                                                    med.administrations.map(
                                                                        (
                                                                            a,
                                                                            idx,
                                                                        ) => (
                                                                            <div
                                                                                key={
                                                                                    a.id ??
                                                                                    `slot-${idx}`
                                                                                }
                                                                                className="flex items-center gap-1"
                                                                            >
                                                                                <TooltipProvider>
                                                                                    <Tooltip>
                                                                                        <TooltipTrigger>
                                                                                            <div
                                                                                                className={`flex items-center gap-1 rounded-md border px-2 py-1 ${
                                                                                                    a.status ===
                                                                                                    'given'
                                                                                                        ? 'border-status-success/30 bg-status-success-bg dark:border-status-success/30 dark:bg-status-success'
                                                                                                        : a.status ===
                                                                                                            'missed'
                                                                                                          ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30 dark:bg-status-critical'
                                                                                                          : a.status ===
                                                                                                              'refused'
                                                                                                            ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30 dark:bg-status-warning'
                                                                                                            : a.status ===
                                                                                                                'withheld'
                                                                                                              ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30 dark:bg-status-warning'
                                                                                                              : 'border-muted bg-muted/30'
                                                                                                }`}
                                                                                            >
                                                                                                {statusIcon(
                                                                                                    a.status,
                                                                                                )}
                                                                                                <span className="font-mono text-xs">
                                                                                                    {a.scheduled_for
                                                                                                        ? new Date(
                                                                                                              a.scheduled_for,
                                                                                                          ).toLocaleTimeString(
                                                                                                              'en-NZ',
                                                                                                              {
                                                                                                                  hour: '2-digit',
                                                                                                                  minute: '2-digit',
                                                                                                              },
                                                                                                          )
                                                                                                        : '—'}
                                                                                                </span>
                                                                                                {getAdministrationAttachments(
                                                                                                    a,
                                                                                                )
                                                                                                    .length >
                                                                                                    0 && (
                                                                                                    <Paperclip className="h-3 w-3 text-muted-foreground" />
                                                                                                )}
                                                                                            </div>
                                                                                        </TooltipTrigger>
                                                                                        <TooltipContent>
                                                                                            <p className="font-medium capitalize">
                                                                                                {
                                                                                                    a.status
                                                                                                }
                                                                                            </p>
                                                                                            {a.administered_by && (
                                                                                                <p>
                                                                                                    By:{' '}
                                                                                                    {
                                                                                                        a.administered_by
                                                                                                    }
                                                                                                </p>
                                                                                            )}
                                                                                            {a.witnessed_by && (
                                                                                                <p>
                                                                                                    Witnessed:{' '}
                                                                                                    {
                                                                                                        a.witnessed_by
                                                                                                    }
                                                                                                </p>
                                                                                            )}
                                                                                            {a.reason && (
                                                                                                <p>
                                                                                                    Reason:{' '}
                                                                                                    {
                                                                                                        a.reason
                                                                                                    }
                                                                                                </p>
                                                                                            )}
                                                                                            {a.notes && (
                                                                                                <p>
                                                                                                    Notes:{' '}
                                                                                                    {
                                                                                                        a.notes
                                                                                                    }
                                                                                                </p>
                                                                                            )}
                                                                                            {a.is_correction && (
                                                                                                <p>
                                                                                                    Correction
                                                                                                    pending{' '}
                                                                                                    {a.correction_status ??
                                                                                                        'review'}
                                                                                                </p>
                                                                                            )}
                                                                                            {getAdministrationAttachments(
                                                                                                a,
                                                                                            )
                                                                                                .length >
                                                                                                0 && (
                                                                                                <p>
                                                                                                    Evidence:{' '}
                                                                                                    {
                                                                                                        getAdministrationAttachments(
                                                                                                            a,
                                                                                                        )
                                                                                                            .length
                                                                                                    }{' '}
                                                                                                    file(s)
                                                                                                </p>
                                                                                            )}
                                                                                        </TooltipContent>
                                                                                    </Tooltip>
                                                                                </TooltipProvider>
                                                                                {a.id && (
                                                                                    <Button
                                                                                        variant="ghost"
                                                                                        size="icon"
                                                                                        className="h-7 w-7"
                                                                                        onClick={() =>
                                                                                            setEvidenceTarget(
                                                                                                {
                                                                                                    administration:
                                                                                                        a,
                                                                                                    medicationName:
                                                                                                        med.name,
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        <Paperclip className="h-3.5 w-3.5" />
                                                                                    </Button>
                                                                                )}
                                                                            </div>
                                                                        ),
                                                                    )
                                                                ) : med
                                                                      .dose_times
                                                                      .length >
                                                                  0 ? (
                                                                    med.dose_times.map(
                                                                        (t) => (
                                                                            <div
                                                                                key={
                                                                                    t
                                                                                }
                                                                                className="flex items-center gap-1 rounded-md border border-muted bg-muted/30 px-2 py-1"
                                                                            >
                                                                                <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                                                                <span className="font-mono text-xs text-muted-foreground">
                                                                                    {
                                                                                        t
                                                                                    }
                                                                                </span>
                                                                            </div>
                                                                        ),
                                                                    )
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        No
                                                                        schedule
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="p-3">
                                                            <div className="flex flex-wrap gap-2">
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        !can.record ||
                                                                        !hasPendingDoses(
                                                                            med,
                                                                        )
                                                                    }
                                                                    onClick={() =>
                                                                        openRecordDialog(
                                                                            med,
                                                                            false,
                                                                        )
                                                                    }
                                                                >
                                                                    <Syringe className="mr-1 h-3 w-3" />
                                                                    Record
                                                                </Button>
                                                                {getLatestRefusalFollowUpTarget(
                                                                    med,
                                                                ) && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        disabled={
                                                                            !can.record
                                                                        }
                                                                        onClick={() =>
                                                                            setFollowUpTarget(
                                                                                {
                                                                                    administrationId:
                                                                                        getLatestRefusalFollowUpTarget(
                                                                                            med,
                                                                                        )
                                                                                            ?.id as number,
                                                                                    medicationName:
                                                                                        med.name,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <Phone className="mr-1 h-3 w-3" />
                                                                        Follow-Up
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                            {(marData?.scheduled ?? [])
                                                .length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={7}
                                                        className="p-6 text-center text-muted-foreground"
                                                    >
                                                        No scheduled
                                                        medications.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* PRN Medications */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    PRN / As-Needed Medications (
                                    {marData?.prn?.length ?? 0})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">
                                                    Medication
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Dose
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Indication
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    24h Usage
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Administrations
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(marData?.prn ?? []).map((med) => (
                                                <tr
                                                    key={med.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="p-3">
                                                        <span className="font-medium">
                                                            {med.name}
                                                        </span>
                                                        {med.controlled_drug && (
                                                            <Badge
                                                                variant="destructive"
                                                                className="ml-2 text-[10px]"
                                                            >
                                                                CD
                                                            </Badge>
                                                        )}
                                                        {med.stock && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                Stock:{' '}
                                                                {
                                                                    med.stock
                                                                        .on_hand
                                                                }{' '}
                                                                {med.stock.unit}
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        {med.dosage}
                                                    </td>
                                                    <td className="p-3 text-xs">
                                                        {med.indication ?? '—'}
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex items-center gap-2">
                                                            <span
                                                                className={`text-sm font-medium ${med.prn_remaining === 0 ? 'text-status-critical' : med.prn_remaining !== null && med.prn_remaining <= 1 ? 'text-status-warning' : ''}`}
                                                            >
                                                                {
                                                                    med.prn_count_24h
                                                                }{' '}
                                                                /{' '}
                                                                {med.max_per_day ??
                                                                    '∞'}
                                                            </span>
                                                            {med.prn_remaining !==
                                                                null && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    (
                                                                    {
                                                                        med.prn_remaining
                                                                    }{' '}
                                                                    remaining)
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            {med.administrations.map(
                                                                (a) => (
                                                                    <div
                                                                        key={
                                                                            a.id
                                                                        }
                                                                        className="flex items-center gap-1"
                                                                    >
                                                                        {statusIcon(
                                                                            a.status,
                                                                        )}
                                                                        <span className="text-xs">
                                                                            {a.administered_at
                                                                                ? new Date(
                                                                                      a.administered_at,
                                                                                  ).toLocaleTimeString(
                                                                                      'en-NZ',
                                                                                      {
                                                                                          hour: '2-digit',
                                                                                          minute: '2-digit',
                                                                                      },
                                                                                  )
                                                                                : '—'}
                                                                        </span>
                                                                        {getAdministrationAttachments(
                                                                            a,
                                                                        )
                                                                            .length >
                                                                            0 && (
                                                                            <Paperclip className="h-3 w-3 text-muted-foreground" />
                                                                        )}
                                                                        {a.id && (
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-6 w-6"
                                                                                onClick={() =>
                                                                                    setEvidenceTarget(
                                                                                        {
                                                                                            administration:
                                                                                                a,
                                                                                            medicationName:
                                                                                                med.name,
                                                                                        },
                                                                                    )
                                                                                }
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                            </Button>
                                                                        )}
                                                                    </div>
                                                                ),
                                                            )}
                                                            {med.administrations
                                                                .length ===
                                                                0 && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    None today
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    !can.record ||
                                                                    med.prn_remaining ===
                                                                        0
                                                                }
                                                                onClick={() =>
                                                                    openRecordDialog(
                                                                        med,
                                                                        true,
                                                                    )
                                                                }
                                                            >
                                                                <Plus className="mr-1 h-3 w-3" />
                                                                Give
                                                            </Button>
                                                            {getLatestRefusalFollowUpTarget(
                                                                med,
                                                            ) && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    disabled={
                                                                        !can.record
                                                                    }
                                                                    onClick={() =>
                                                                        setFollowUpTarget(
                                                                            {
                                                                                administrationId:
                                                                                    getLatestRefusalFollowUpTarget(
                                                                                        med,
                                                                                    )
                                                                                        ?.id as number,
                                                                                medicationName:
                                                                                    med.name,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <Phone className="mr-1 h-3 w-3" />
                                                                    Follow-Up
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                            {(marData?.prn ?? []).length ===
                                                0 && (
                                                <tr>
                                                    <td
                                                        colSpan={6}
                                                        className="p-6 text-center text-muted-foreground"
                                                    >
                                                        No PRN medications.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        <RecordAdministrationDialog
                            isOpen={dialogOpen}
                            onClose={() => {
                                setDialogOpen(false);
                                setSelectedMed(null);
                                setSelectedScheduledTime(null);
                            }}
                            onSubmit={handleSubmit}
                            medication={mappedMedication}
                            clientId={selectedClient?.id ?? null}
                            scheduledTime={selectedScheduledTime}
                            witnesses={staff.filter(
                                (s) => s.id !== auth.user.id,
                            )}
                            currentUserId={auth.user.id}
                            safetyCheck={safetyCheck}
                            prnData={prnHistoryData}
                            isLoading={loadingSafety}
                        />

                        <AdministrationEvidenceDialog
                            isOpen={!!evidenceTarget}
                            onClose={() => setEvidenceTarget(null)}
                            clientId={selectedClient?.id ?? null}
                            medicationName={
                                evidenceTarget?.medicationName ?? ''
                            }
                            administration={
                                evidenceTarget?.administration ?? null
                            }
                            canManage={can.record || can.correct}
                            onAttachmentsChange={handleAttachmentsChange}
                        />

                        {followUpTarget && selectedClient && (
                            <RefusalFollowUpDialog
                                isOpen={!!followUpTarget}
                                onClose={() => setFollowUpTarget(null)}
                                administrationId={
                                    followUpTarget.administrationId
                                }
                                clientId={selectedClient.id}
                                medicationName={followUpTarget.medicationName}
                            />
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
