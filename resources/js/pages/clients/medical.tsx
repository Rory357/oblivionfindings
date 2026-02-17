import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    TabsRoot,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import {
    ChevronDown,
    Plus,
    Pill,
    Stethoscope,
    AlertCircle,
    UserCircle,
    ClipboardList,
    Package,
    ShieldAlert,
    FileText,
    Clock,
    CheckCircle2,
    XCircle,
    AlertTriangle,
    Download,
    History,
} from 'lucide-react';
import { useState, useMemo } from 'react';
import { cn } from '@/lib/utils';
import MedicationVersionHistory from '@/components/medications/MedicationVersionHistory';
import ScheduledStockCounts from '@/components/medications/ScheduledStockCounts';
import DrugInteractionManager from '@/components/medications/DrugInteractionManager';

interface Medication {
    id: number;
    name: string;
    dosage?: string;
    frequency?: string;
    route?: string;
    instructions?: string;
    is_prn: boolean;
    prn_reason?: string;
    max_per_day?: string;
    controlled_drug: boolean;
    state: 'active' | 'paused' | 'ceased';
    active: boolean;
    prescriber?: string;
    pharmacy?: string;
    form?: string;
    dose_times?: string[];
    start_date?: string;
    end_date?: string;
    ceased_at?: string;
    ceased_reason?: string;
    stock?: {
        on_hand?: number;
        unit?: string;
        reorder_level?: number;
        notes?: string;
    };
}

interface Administration {
    id: number;
    status: 'given' | 'refused' | 'missed' | 'withheld';
    administered_at?: string;
    dose_given?: string;
    reason?: string;
    notes?: string;
    late_minutes?: number;
    medication?: Medication;
    administeredBy?: { name: string };
    serviceContext?: { name: string };
}

interface Condition {
    id: number;
    label: string;
    severity?: string;
    notes?: string;
}

interface EmergencyContact {
    id: number;
    name: string;
    relationship?: string;
    phone?: string;
    email?: string;
    notes?: string;
}

interface PageProps {
    client: {
        id: number;
        first_name: string;
        last_name: string;
    };
    can_edit: boolean;
    can_record: boolean;
    can_stock: boolean;
    can_controlled_record: boolean;
    can_controlled_view: boolean;
    profile: {
        medical_history?: string;
        disabilities?: string;
        allergies?: string;
        notes?: string;
    };
    medications: Medication[];
    conditions: Condition[];
    emergency_contacts: EmergencyContact[];
    administrations: Administration[];
    witnesses: { id: number; name: string }[];
    controlled_entries: any[];
    controlled_discrepancies: any[];
    med_charts: any[];
}

function StatusBadge({ state, active }: { state: string; active?: boolean }) {
    const variants: Record<string, string> = {
        active: 'bg-green-100 text-green-800 hover:bg-green-100',
        paused: 'bg-amber-100 text-amber-800 hover:bg-amber-100',
        ceased: 'bg-slate-100 text-slate-800 hover:bg-slate-100',
    };
    const label = state === 'active' && !active ? 'Inactive' : state.charAt(0).toUpperCase() + state.slice(1);
    return (
        <Badge variant="outline" className={cn('text-xs font-medium', variants[state] || variants.ceased)}>
            {label}
        </Badge>
    );
}

function AdminStatusBadge({ status }: { status: string }) {
    const config: Record<string, { class: string; icon: React.ReactNode }> = {
        given: { class: 'bg-green-100 text-green-800 border-green-200', icon: <CheckCircle2 className="h-3 w-3 mr-1" /> },
        refused: { class: 'bg-red-100 text-red-800 border-red-200', icon: <XCircle className="h-3 w-3 mr-1" /> },
        missed: { class: 'bg-amber-100 text-amber-800 border-amber-200', icon: <AlertTriangle className="h-3 w-3 mr-1" /> },
        withheld: { class: 'bg-orange-100 text-orange-800 border-orange-200', icon: <AlertCircle className="h-3 w-3 mr-1" /> },
    };
    const c = config[status] || config.missed;
    return (
        <Badge variant="outline" className={cn('text-xs font-medium flex items-center', c.class)}>
            {c.icon}
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </Badge>
    );
}

function ControlledBadge({ isControlled }: { isControlled: boolean }) {
    if (!isControlled) return null;
    return (
        <Badge variant="outline" className="bg-purple-100 text-purple-800 border-purple-200 text-xs">
            <ShieldAlert className="h-3 w-3 mr-1" />
            Controlled
        </Badge>
    );
}

function PrnBadge({ isPrn }: { isPrn: boolean }) {
    if (!isPrn) return null;
    return (
        <Badge variant="outline" className="bg-blue-100 text-blue-800 border-blue-200 text-xs">
            PRN
        </Badge>
    );
}

interface MedicationSummaryCardProps {
    med: Medication;
    clientId: number;
    onDelete?: () => void;
    canEdit: boolean;
    canStock: boolean;
    witnesses: { id: number; name: string }[];
}

function MedicationSummaryCard({ med, clientId, onDelete, canEdit, canStock, witnesses }: MedicationSummaryCardProps) {
    const [showDetails, setShowDetails] = useState(false);
    const isLowStock = med.stock?.on_hand !== undefined && med.stock?.reorder_level !== undefined && 
        med.stock.on_hand <= med.stock.reorder_level;

    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-medium text-sm">{med.name}</span>
                        <StatusBadge state={med.state} active={med.active} />
                        <ControlledBadge isControlled={med.controlled_drug} />
                        <PrnBadge isPrn={med.is_prn} />
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {med.dosage && <span className="mr-2">{med.dosage}</span>}
                        {med.frequency && <span className="mr-2">{med.frequency}</span>}
                        {med.route && <span>{med.route}</span>}
                    </div>
                    {med.stock && (
                        <div className="mt-2 flex items-center gap-2 text-xs">
                            <Package className="h-3 w-3 text-muted-foreground" />
                            <span className={cn(isLowStock && 'text-amber-600 font-medium')}>
                                Stock: {med.stock.on_hand ?? '—'} {med.stock.unit}
                            </span>
                            {isLowStock && <Badge variant="outline" className="text-amber-600 border-amber-300 text-[10px]">Low</Badge>}
                        </div>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => setShowDetails(!showDetails)}>
                        {showDetails ? 'Less' : 'More'}
                        <ChevronDown className={cn('ml-1 h-3 w-3 transition-transform', showDetails && 'rotate-180')} />
                    </Button>
                    {canEdit && (
                        <Button variant="ghost" size="sm" className="h-7 px-2 text-destructive" onClick={onDelete}>
                            <XCircle className="h-3 w-3" />
                        </Button>
                    )}
                </div>
            </div>
            
            {/* Action buttons for version history and stock counts */}
            <div className="mt-2 flex items-center gap-2">
                <MedicationVersionHistory
                    clientId={clientId}
                    medicationId={med.id}
                    medicationName={med.name}
                />
                {canStock && (
                    <ScheduledStockCounts
                        clientId={clientId}
                        medicationId={med.id}
                        medicationName={med.name}
                        controlledDrug={med.controlled_drug}
                        witnesses={witnesses}
                    />
                )}
            </div>
            
            {showDetails && (
                <div className="mt-3 pt-3 border-t space-y-2 text-xs">
                    {med.instructions && (
                        <div>
                            <span className="text-muted-foreground">Instructions:</span>
                            <p className="mt-0.5 text-foreground">{med.instructions}</p>
                        </div>
                    )}
                    {med.is_prn && med.prn_reason && (
                        <div>
                            <span className="text-muted-foreground">PRN indication:</span>
                            <p className="mt-0.5">{med.prn_reason}</p>
                            {med.max_per_day && <span className="text-muted-foreground">Max/day: {med.max_per_day}</span>}
                        </div>
                    )}
                    {med.prescriber && <div><span className="text-muted-foreground">Prescriber:</span> {med.prescriber}</div>}
                    {med.pharmacy && <div><span className="text-muted-foreground">Pharmacy:</span> {med.pharmacy}</div>}
                    {med.dose_times && med.dose_times.length > 0 && (
                        <div className="flex items-center gap-1">
                            <Clock className="h-3 w-3 text-muted-foreground" />
                            <span className="text-muted-foreground">Times:</span>
                            {med.dose_times.map((t, i) => (
                                <Badge key={i} variant="secondary" className="text-[10px]">{t}</Badge>
                            ))}
                        </div>
                    )}
                    {med.start_date && (
                        <div>
                            <span className="text-muted-foreground">Period:</span>{' '}
                            {new Date(med.start_date).toLocaleDateString()}
                            {med.end_date ? ` - ${new Date(med.end_date).toLocaleDateString()}` : ' (ongoing)'}
                        </div>
                    )}
                    {med.ceased_at && (
                        <div className="text-destructive">
                            Ceased: {new Date(med.ceased_at).toLocaleDateString()}
                            {med.ceased_reason && ` - ${med.ceased_reason}`}
                        </div>
                    )}
                    {med.stock?.notes && (
                        <div className="text-muted-foreground italic">{med.stock.notes}</div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Medical() {
    const {
        client,
        can_edit,
        can_record,
        can_stock,
        can_controlled_record,
        can_controlled_view,
        profile,
        medications,
        conditions,
        emergency_contacts,
        administrations,
        witnesses,
        controlled_entries,
        controlled_discrepancies,
        med_charts,
    } = usePage<PageProps>().props;

        // Safety checks for arrays
    const safeConditions = conditions || [];
    const safeEmergencyContacts = emergency_contacts || [];
    const safeAdministrations = administrations || [];
    const safeControlledEntries = controlled_entries || [];
    const safeControlledDiscrepancies = controlled_discrepancies || [];
    const safeMedCharts = med_charts || [];
    
    const [activeTab, setActiveTab] = useState('overview');
    const [showAddMed, setShowAddMed] = useState(false);
    const [showAddCondition, setShowAddCondition] = useState(false);
    const [showAddContact, setShowAddContact] = useState(false);
    const [confirmAdminOpen, setConfirmAdminOpen] = useState(false);
    const [closeDiscOpen, setCloseDiscOpen] = useState(false);
    const [selectedDiscId, setSelectedDiscId] = useState<number | null>(null);

    const profileForm = useForm({
        medical_history: profile?.medical_history ?? '',
        disabilities: profile?.disabilities ?? '',
        allergies: profile?.allergies ?? '',
        notes: profile?.notes ?? '',
    });

    const medForm = useForm({
        name: '',
        dosage: '',
        frequency: '',
        is_prn: false,
        prn_reason: '',
        max_per_day: '',
        controlled_drug: false,
        route: '',
        prescriber: '',
        pharmacy: '',
        form: '',
        dose_times: '',
        state: 'active',
        instructions: '',
        active: true,
        start_date: '',
        end_date: '',
        ceased_at: '',
        ceased_reason: '',
    });

    const conditionForm = useForm({ label: '', severity: '', notes: '' });
    const contactForm = useForm({ name: '', relationship: '', phone: '', email: '', notes: '' });

    const administrationForm = useForm({
        medication_id: '',
        status: 'given',
        reason: '',
        notes: '',
        dose_given: '',
        administered_at: '',
        scheduled_for: '',
        witnessed_by: '',
    });

    const stockForm = useForm({
        medication_id: '',
        on_hand: '',
        unit: '',
        reorder_level: '',
        notes: '',
        reason: '',
        witnessed_by: '',
        last_counted_at: '',
    });

    const closeDiscForm = useForm({ resolution_notes: '' });

    const selectedMedication = useMemo(() => 
        medications.find((m) => String(m.id) === administrationForm.data.medication_id),
    [medications, administrationForm.data.medication_id]);

    const selectedStockMedication = useMemo(() => 
        medications.find((m) => String(m.id) === stockForm.data.medication_id),
    [medications, stockForm.data.medication_id]);

    const administrationNeedsReason = useMemo(() => {
        const s = administrationForm.data.status;
        return s === 'refused' || s === 'missed' || s === 'withheld' || selectedMedication?.is_prn;
    }, [administrationForm.data.status, selectedMedication]);

    const submitAdministration = () => {
        administrationForm.post(`/clients/${client.id}/medical/administrations`, {
            preserveScroll: true,
            onSuccess: () => {
                administrationForm.reset();
                setConfirmAdminOpen(false);
            },
        });
    };

    const safeMedications = medications || [];
    const activeMeds = safeMedications.filter(m => m.state === 'active');
    const prnMeds = safeMedications.filter(m => m.is_prn && m.state === 'active');
    const controlledMeds = safeMedications.filter(m => m.controlled_drug);

    return (
        <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }, { title: `${client.first_name} ${client.last_name}`, href: `/clients/${client.id}` }, { title: 'Medical' }]}>
            <Head title={`Medical - ${client.first_name} ${client.last_name}`} />

            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Medical Profile</h1>
                        <p className="text-sm text-muted-foreground">
                            {client.first_name} {client.last_name}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/clients/${client.id}/documents`}>
                                <FileText className="mr-2 h-4 w-4" />
                                Documents
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/medications?client_id=${client.id}`}>
                                <ClipboardList className="mr-2 h-4 w-4" />
                                MAR View
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Medication Charts Alert */}
                {safeMedCharts.length > 0 && (
                    <div className="rounded-lg border bg-amber-50/50 p-4">
                        <div className="flex items-center gap-2 mb-2">
                            <FileText className="h-4 w-4 text-amber-600" />
                            <span className="font-medium text-sm">Medication Charts (Source of Truth)</span>
                        </div>
                        <div className="space-y-2">
                            {safeMedCharts.map((d: any) => (
                                <div key={d.id} className="flex items-center justify-between rounded-md border bg-white p-3">
                                    <div>
                                        <div className="text-sm font-medium">{d.title}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {d.version ? `v${d.version} • ` : ''}
                                            {d.effective_date ? `Effective: ${new Date(d.effective_date).toLocaleDateString()}` : ''}
                                        </div>
                                    </div>
                                    <Button variant="outline" size="sm" onClick={() => window.location.href = `/clients/${client.id}/documents/${d.id}/download`}>
                                        <Download className="mr-2 h-4 w-4" />
                                        Download
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Main Content Tabs */}
                <TabsRoot value={activeTab} onValueChange={setActiveTab} className="space-y-4">
                    <TabsList className="inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground w-full">
                        <TabsTrigger value="overview" className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm">
                            <Stethoscope className="h-4 w-4" />
                            <span>Overview</span>
                        </TabsTrigger>
                        <TabsTrigger value="medications" className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm">
                            <Pill className="h-4 w-4" />
                            <span>Medications</span>
                            <Badge variant="secondary" className="ml-1 h-5 px-1.5 text-[10px]">{activeMeds.length}</Badge>
                        </TabsTrigger>
                        <TabsTrigger value="profile" className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm">
                            <UserCircle className="h-4 w-4" />
                            <span>Profile</span>
                        </TabsTrigger>
                        <TabsTrigger value="administration" className="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm">
                            <ClipboardList className="h-4 w-4" />
                            <span>MAR</span>
                        </TabsTrigger>
                    </TabsList>

                    {/* Overview Tab */}
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Active Medications</CardDescription>
                                    <CardTitle className="text-3xl">{activeMeds.length}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-xs text-muted-foreground">
                                        {prnMeds.length} PRN • {controlledMeds.length} Controlled
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Today's Administrations</CardDescription>
                                    <CardTitle className="text-3xl">
                                        {administrations.filter(a => a.administered_at && new Date(a.administered_at).toDateString() === new Date().toDateString()).length}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-xs text-muted-foreground">
                                        Last 24 hours
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Conditions</CardDescription>
                                    <CardTitle className="text-3xl">{conditions.length}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-xs text-muted-foreground">
                                        Active diagnoses
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardDescription>Emergency Contacts</CardDescription>
                                    <CardTitle className="text-3xl">{emergency_contacts.length}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-xs text-muted-foreground">
                                        On file
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            {/* Quick Profile */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <AlertCircle className="h-4 w-4" />
                                        Key Information
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {profile?.allergies ? (
                                        <div className="rounded-md bg-red-50 border border-red-200 p-3">
                                            <div className="text-sm font-medium text-red-800">Allergies</div>
                                            <div className="text-sm text-red-700 mt-1">{profile.allergies}</div>
                                        </div>
                                    ) : (
                                        <div className="text-sm text-muted-foreground">No allergies recorded</div>
                                    )}
                                    {profile?.medical_history && (
                                        <div>
                                            <div className="text-sm font-medium">Medical History</div>
                                            <div className="text-sm text-muted-foreground mt-1 line-clamp-3">{profile.medical_history}</div>
                                        </div>
                                    )}
                                    {profile?.disabilities && (
                                        <div>
                                            <div className="text-sm font-medium">Disabilities</div>
                                            <div className="text-sm text-muted-foreground mt-1 line-clamp-3">{profile.disabilities}</div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Recent Administrations */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <History className="h-4 w-4" />
                                        Recent Administrations
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2 max-h-[240px] overflow-y-auto">
                                        {administrations.slice(0, 5).map((a) => (
                                            <div key={a.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-medium truncate">{a.medication?.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {a.administered_at ? new Date(a.administered_at).toLocaleString() : 'Not recorded'}
                                                    </div>
                                                </div>
                                                <AdminStatusBadge status={a.status} />
                                            </div>
                                        ))}
                                        {!administrations.length && (
                                            <div className="text-sm text-muted-foreground text-center py-4">
                                                No administrations recorded yet
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Medications Tab */}
                    <TabsContent value="medications" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Medications List */}
                            <div className="lg:col-span-2 space-y-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-medium">Medication List</h3>
                                    <div className="flex items-center gap-2">
                                        <DrugInteractionManager canManage={can_edit} />
                                        {can_edit && (
                                            <Button size="sm" onClick={() => setShowAddMed(true)}>
                                                <Plus className="mr-2 h-4 w-4" />
                                                Add Medication
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                {showAddMed && can_edit && (
                                    <Card className="border-dashed">
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-sm">Add New Medication</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="col-span-2">
                                                    <Label>Medication Name</Label>
                                                    <Input value={medForm.data.name} onChange={(e) => medForm.setData('name', e.target.value)} placeholder="e.g. Paracetamol" />
                                                </div>
                                                <div>
                                                    <Label>Dosage</Label>
                                                    <Input value={medForm.data.dosage} onChange={(e) => medForm.setData('dosage', e.target.value)} placeholder="e.g. 500mg" />
                                                </div>
                                                <div>
                                                    <Label>Route</Label>
                                                    <Input value={medForm.data.route} onChange={(e) => medForm.setData('route', e.target.value)} placeholder="e.g. Oral" />
                                                </div>
                                                <div>
                                                    <Label>Frequency</Label>
                                                    <Input value={medForm.data.frequency} onChange={(e) => medForm.setData('frequency', e.target.value)} placeholder="e.g. Twice daily" />
                                                </div>
                                                <div>
                                                    <Label>Form</Label>
                                                    <Input value={medForm.data.form} onChange={(e) => medForm.setData('form', e.target.value)} placeholder="Tablet, Liquid..." />
                                                </div>
                                            </div>

                                            <Collapsible>
                                                <CollapsibleTrigger asChild>
                                                    <Button variant="ghost" size="sm" className="w-full justify-between">
                                                        <span>Additional Details</span>
                                                        <ChevronDown className="h-4 w-4" />
                                                    </Button>
                                                </CollapsibleTrigger>
                                                <CollapsibleContent className="space-y-4 pt-4">
                                                    <div className="grid grid-cols-2 gap-3">
                                                        <div>
                                                            <Label>Prescriber</Label>
                                                            <Input value={medForm.data.prescriber} onChange={(e) => medForm.setData('prescriber', e.target.value)} />
                                                        </div>
                                                        <div>
                                                            <Label>Pharmacy</Label>
                                                            <Input value={medForm.data.pharmacy} onChange={(e) => medForm.setData('pharmacy', e.target.value)} />
                                                        </div>
                                                        <div>
                                                            <Label>Start Date</Label>
                                                            <Input type="date" value={medForm.data.start_date} onChange={(e) => medForm.setData('start_date', e.target.value)} />
                                                        </div>
                                                        <div>
                                                            <Label>End Date</Label>
                                                            <Input type="date" value={medForm.data.end_date} onChange={(e) => medForm.setData('end_date', e.target.value)} />
                                                        </div>
                                                        <div>
                                                            <Label>Dose Times</Label>
                                                            <Input value={medForm.data.dose_times} onChange={(e) => medForm.setData('dose_times', e.target.value)} placeholder="08:00, 12:00, 18:00" />
                                                        </div>
                                                        <div>
                                                            <Label>State</Label>
                                                            <Select value={medForm.data.state} onValueChange={(v) => medForm.setData('state', v)}>
                                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="active">Active</SelectItem>
                                                                    <SelectItem value="paused">Paused</SelectItem>
                                                                    <SelectItem value="ceased">Ceased</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <Label>Instructions</Label>
                                                        <Textarea value={medForm.data.instructions} onChange={(e) => medForm.setData('instructions', e.target.value)} />
                                                    </div>
                                                    <div className="flex gap-4">
                                                        <div className="flex items-center gap-2">
                                                            <Checkbox checked={medForm.data.is_prn} onCheckedChange={(v) => medForm.setData('is_prn', !!v)} />
                                                            <Label className="!mt-0 text-sm">PRN (as needed)</Label>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Checkbox checked={medForm.data.controlled_drug} onCheckedChange={(v) => medForm.setData('controlled_drug', !!v)} />
                                                            <Label className="!mt-0 text-sm">Controlled Drug</Label>
                                                        </div>
                                                    </div>
                                                    {medForm.data.is_prn && (
                                                        <div className="grid grid-cols-2 gap-3">
                                                            <div>
                                                                <Label>PRN Indication</Label>
                                                                <Input value={medForm.data.prn_reason} onChange={(e) => medForm.setData('prn_reason', e.target.value)} />
                                                            </div>
                                                            <div>
                                                                <Label>Max per Day</Label>
                                                                <Input value={medForm.data.max_per_day} onChange={(e) => medForm.setData('max_per_day', e.target.value)} />
                                                            </div>
                                                        </div>
                                                    )}
                                                </CollapsibleContent>
                                            </Collapsible>

                                            <div className="flex gap-2">
                                                <Button
                                                    onClick={() => {
                                                        const dt = typeof medForm.data.dose_times === 'string' && medForm.data.dose_times
                                                            ? medForm.data.dose_times.split(',').map(s => s.trim()).filter(Boolean)
                                                            : medForm.data.dose_times;
                                                        medForm.setData('dose_times', dt as any);
                                                        medForm.post(`/clients/${client.id}/medical/medications`, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                medForm.reset();
                                                                setShowAddMed(false);
                                                            },
                                                        });
                                                    }}
                                                    disabled={medForm.processing || !medForm.data.name.trim()}
                                                >
                                                    Add Medication
                                                </Button>
                                                <Button variant="outline" onClick={() => setShowAddMed(false)}>Cancel</Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                <div className="space-y-3">
                                    {safeMedications.map((m) => (
                                        <MedicationSummaryCard
                                            key={m.id}
                                            med={m}
                                            clientId={client.id}
                                            canEdit={can_edit}
                                            canStock={can_stock}
                                            witnesses={witnesses}
                                            onDelete={() => {
                                                if (confirm(`Remove ${m.name}?`)) {
                                                    medForm.delete(`/clients/${client.id}/medical/medications/${m.id}`, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    ))}
                                    {!medications.length && (
                                        <div className="text-center py-8 text-muted-foreground">
                                            No medications recorded
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Stock Panel */}
                            <div className="space-y-4">
                                <h3 className="font-medium flex items-center gap-2">
                                    <Package className="h-4 w-4" />
                                    Stock Management
                                </h3>
                                
                                {can_stock && medications.length > 0 && (
                                    <Card>
                                        <CardContent className="pt-4 space-y-3">
                                            <div>
                                                <Label>Medication</Label>
                                                <Select value={stockForm.data.medication_id} onValueChange={(v) => stockForm.setData('medication_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select medication" /></SelectTrigger>
                                                    <SelectContent>
                                                        {medications.map((m) => (
                                                            <SelectItem key={m.id} value={`${m.id}`}>{m.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            {selectedStockMedication?.controlled_drug && (
                                                <>
                                                    <div>
                                                        <Label>Reason (required)</Label>
                                                        <Input value={stockForm.data.reason} onChange={(e) => stockForm.setData('reason', e.target.value)} />
                                                    </div>
                                                    <div>
                                                        <Label>Witness</Label>
                                                        <Select value={stockForm.data.witnessed_by} onValueChange={(v) => stockForm.setData('witnessed_by', v)}>
                                                            <SelectTrigger><SelectValue placeholder="Select witness" /></SelectTrigger>
                                                            <SelectContent>
                                                                {witnesses.map((w: any) => (
                                                                    <SelectItem key={w.id} value={`${w.id}`}>{w.name}</SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </>
                                            )}
                                            <div className="grid grid-cols-2 gap-2">
                                                <div>
                                                    <Label>On Hand</Label>
                                                    <Input value={stockForm.data.on_hand} onChange={(e) => stockForm.setData('on_hand', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Unit</Label>
                                                    <Input value={stockForm.data.unit} onChange={(e) => stockForm.setData('unit', e.target.value)} placeholder="tablets" />
                                                </div>
                                            </div>
                                            <div>
                                                <Label>Reorder Level</Label>
                                                <Input value={stockForm.data.reorder_level} onChange={(e) => stockForm.setData('reorder_level', e.target.value)} />
                                            </div>
                                            <Button
                                                className="w-full"
                                                onClick={() => {
                                                    if (!stockForm.data.medication_id) return;
                                                    if (selectedStockMedication?.controlled_drug && (!stockForm.data.reason || !stockForm.data.witnessed_by)) {
                                                        return;
                                                    }
                                                    stockForm.put(`/clients/${client.id}/medical/medications/${stockForm.data.medication_id}/stock`, { preserveScroll: true });
                                                }}
                                                disabled={!stockForm.data.medication_id || stockForm.processing}
                                            >
                                                Update Stock
                                            </Button>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        </div>

                        {/* Controlled Drug Register */}
                        {can_controlled_view && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <ShieldAlert className="h-4 w-4 text-purple-600" />
                                        Controlled Drug Register
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2 max-h-[300px] overflow-y-auto">
                                        {safeControlledEntries.map((e: any) => (
                                            <div key={e.id} className="rounded-md border p-3 text-sm">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-medium">{e.medication?.name}</span>
                                                    <Badge variant="outline" className="text-xs">{e.entry_type}</Badge>
                                                </div>
                                                <div className="text-xs text-muted-foreground mt-1">
                                                    {e.recorded_at ? new Date(e.recorded_at).toLocaleString() : ''}
                                                    {e.recordedBy?.name ? ` • ${e.recordedBy.name}` : ''}
                                                    {e.witnessedBy?.name ? ` (witness: ${e.witnessedBy.name})` : ''}
                                                </div>
                                                {(e.on_hand_before !== null || e.on_hand_after !== null) && (
                                                    <div className="text-xs mt-1">
                                                        Stock: {e.on_hand_before ?? '—'} → {e.on_hand_after ?? '—'} {e.unit}
                                                    </div>
                                                )}
                                                {e.reason && <div className="text-xs text-muted-foreground mt-1">Reason: {e.reason}</div>}
                                            </div>
                                        ))}
                                        {!controlled_entries.length && (
                                            <div className="text-sm text-muted-foreground text-center py-4">No controlled drug entries recorded</div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* Profile Tab */}
                    <TabsContent value="profile" className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Medical History</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label>Medical History</Label>
                                        <Textarea
                                            value={profileForm.data.medical_history}
                                            onChange={(e) => profileForm.setData('medical_history', e.target.value)}
                                            disabled={!can_edit}
                                            className="min-h-[100px]"
                                        />
                                    </div>
                                    <div>
                                        <Label>Disabilities</Label>
                                        <Textarea
                                            value={profileForm.data.disabilities}
                                            onChange={(e) => profileForm.setData('disabilities', e.target.value)}
                                            disabled={!can_edit}
                                            className="min-h-[100px]"
                                        />
                                    </div>
                                    <div>
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={profileForm.data.notes}
                                            onChange={(e) => profileForm.setData('notes', e.target.value)}
                                            disabled={!can_edit}
                                            className="min-h-[80px]"
                                        />
                                    </div>
                                    {can_edit && (
                                        <Button
                                            onClick={() => profileForm.put(`/clients/${client.id}/medical/profile`, { preserveScroll: true })}
                                            disabled={profileForm.processing}
                                        >
                                            Save Profile
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>

                            <div className="space-y-4">
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">Conditions</CardTitle>
                                        {can_edit && (
                                            <Button size="sm" variant="outline" onClick={() => setShowAddCondition(!showAddCondition)}>
                                                <Plus className="h-4 w-4" />
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {showAddCondition && can_edit && (
                                            <div className="rounded-md border p-3 space-y-3">
                                                <div>
                                                    <Label>Condition</Label>
                                                    <Input value={conditionForm.data.label} onChange={(e) => conditionForm.setData('label', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Severity</Label>
                                                    <Input value={conditionForm.data.severity} onChange={(e) => conditionForm.setData('severity', e.target.value)} placeholder="mild / moderate / severe" />
                                                </div>
                                                <div>
                                                    <Label>Notes</Label>
                                                    <Textarea value={conditionForm.data.notes} onChange={(e) => conditionForm.setData('notes', e.target.value)} />
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        onClick={() => conditionForm.post(`/clients/${client.id}/medical/conditions`, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                conditionForm.reset();
                                                                setShowAddCondition(false);
                                                            },
                                                        })}
                                                        disabled={conditionForm.processing || !conditionForm.data.label.trim()}
                                                    >
                                                        Add
                                                    </Button>
                                                    <Button size="sm" variant="outline" onClick={() => setShowAddCondition(false)}>Cancel</Button>
                                                </div>
                                            </div>
                                        )}
                                        <div className="space-y-2">
                                            {safeConditions.map((c) => (
                                                <div key={c.id} className="flex items-center justify-between rounded-md border p-3">
                                                    <div>
                                                        <div className="font-medium text-sm">{c.label}</div>
                                                        {c.severity && <Badge variant="secondary" className="mt-1 text-[10px]">{c.severity}</Badge>}
                                                        {c.notes && <div className="text-xs text-muted-foreground mt-1">{c.notes}</div>}
                                                    </div>
                                                    {can_edit && (
                                                        <Button variant="ghost" size="sm" className="text-destructive" onClick={() => conditionForm.delete(`/clients/${client.id}/medical/conditions/${c.id}`, { preserveScroll: true })}>
                                                            <XCircle className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                            ))}
                                            {!conditions.length && <div className="text-sm text-muted-foreground">No conditions recorded</div>}
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">Emergency Contacts</CardTitle>
                                        {can_edit && (
                                            <Button size="sm" variant="outline" onClick={() => setShowAddContact(!showAddContact)}>
                                                <Plus className="h-4 w-4" />
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {showAddContact && can_edit && (
                                            <div className="rounded-md border p-3 space-y-3">
                                                <div className="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <Label>Name</Label>
                                                        <Input value={contactForm.data.name} onChange={(e) => contactForm.setData('name', e.target.value)} />
                                                    </div>
                                                    <div>
                                                        <Label>Relationship</Label>
                                                        <Input value={contactForm.data.relationship} onChange={(e) => contactForm.setData('relationship', e.target.value)} />
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <Label>Phone</Label>
                                                        <Input value={contactForm.data.phone} onChange={(e) => contactForm.setData('phone', e.target.value)} />
                                                    </div>
                                                    <div>
                                                        <Label>Email</Label>
                                                        <Input value={contactForm.data.email} onChange={(e) => contactForm.setData('email', e.target.value)} />
                                                    </div>
                                                </div>
                                                <div>
                                                    <Label>Notes</Label>
                                                    <Textarea value={contactForm.data.notes} onChange={(e) => contactForm.setData('notes', e.target.value)} />
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        onClick={() => contactForm.post(`/clients/${client.id}/medical/emergency-contacts`, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                contactForm.reset();
                                                                setShowAddContact(false);
                                                            },
                                                        })}
                                                        disabled={contactForm.processing || !contactForm.data.name.trim()}
                                                    >
                                                        Add
                                                    </Button>
                                                    <Button size="sm" variant="outline" onClick={() => setShowAddContact(false)}>Cancel</Button>
                                                </div>
                                            </div>
                                        )}
                                        <div className="space-y-2">
                                            {safeEmergencyContacts.map((e) => (
                                                <div key={e.id} className="rounded-md border p-3">
                                                    <div className="flex items-center justify-between">
                                                        <span className="font-medium text-sm">{e.name}</span>
                                                        {can_edit && (
                                                            <Button variant="ghost" size="sm" className="text-destructive h-7" onClick={() => contactForm.delete(`/clients/${client.id}/medical/emergency-contacts/${e.id}`, { preserveScroll: true })}>
                                                                <XCircle className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground mt-1">
                                                        {e.relationship && <span className="mr-2">{e.relationship}</span>}
                                                        {e.phone && <span className="mr-2">{e.phone}</span>}
                                                        {e.email && <span>{e.email}</span>}
                                                    </div>
                                                    {e.notes && <div className="text-xs text-muted-foreground mt-1">{e.notes}</div>}
                                                </div>
                                            ))}
                                            {!emergency_contacts.length && <div className="text-sm text-muted-foreground">No emergency contacts recorded</div>}
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>

                    {/* Administration Tab */}
                    <TabsContent value="administration" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Record Administration */}
                            {can_record && medications.length > 0 && (
                                <Card className="lg:col-span-1">
                                    <CardHeader>
                                        <CardTitle className="text-base">Record Administration</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div>
                                            <Label>Medication</Label>
                                            <Select value={administrationForm.data.medication_id} onValueChange={(v) => administrationForm.setData('medication_id', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select medication" /></SelectTrigger>
                                                <SelectContent>
                                                    {medications.map((m) => (
                                                        <SelectItem key={m.id} value={`${m.id}`}>{m.name}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label>Status</Label>
                                            <Select value={administrationForm.data.status} onValueChange={(v) => administrationForm.setData('status', v)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="given">Given</SelectItem>
                                                    <SelectItem value="refused">Refused</SelectItem>
                                                    <SelectItem value="missed">Missed</SelectItem>
                                                    <SelectItem value="withheld">Withheld</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label>Dose Given</Label>
                                            <Input value={administrationForm.data.dose_given} onChange={(e) => administrationForm.setData('dose_given', e.target.value)} />
                                        </div>
                                        <div>
                                            <Label>Administered At</Label>
                                            <Input type="datetime-local" value={administrationForm.data.administered_at} onChange={(e) => administrationForm.setData('administered_at', e.target.value)} />
                                        </div>
                                        {administrationNeedsReason && (
                                            <div>
                                                <Label>{selectedMedication?.is_prn ? 'Indication (required for PRN)' : 'Reason'}</Label>
                                                <Input value={administrationForm.data.reason} onChange={(e) => administrationForm.setData('reason', e.target.value)} />
                                            </div>
                                        )}
                                        {selectedMedication?.controlled_drug && administrationForm.data.status === 'given' && (
                                            <div>
                                                <Label>Witness (required)</Label>
                                                <Select value={administrationForm.data.witnessed_by} onValueChange={(v) => administrationForm.setData('witnessed_by', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select witness" /></SelectTrigger>
                                                    <SelectContent>
                                                        {witnesses.map((w: any) => (
                                                            <SelectItem key={w.id} value={`${w.id}`}>{w.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}
                                        <div>
                                            <Label>Notes</Label>
                                            <Textarea value={administrationForm.data.notes} onChange={(e) => administrationForm.setData('notes', e.target.value)} />
                                        </div>
                                        <Button
                                            className="w-full"
                                            onClick={() => {
                                                administrationForm.clearErrors();
                                                if (!administrationForm.data.medication_id) return;
                                                if (administrationNeedsReason && !administrationForm.data.reason) {
                                                    administrationForm.setError('reason', 'A reason/indication is required.');
                                                    return;
                                                }
                                                if (selectedMedication?.controlled_drug && administrationForm.data.status === 'given' && !administrationForm.data.witnessed_by) {
                                                    administrationForm.setError('witnessed_by', 'A witness is required for controlled drug administration.');
                                                    return;
                                                }
                                                setConfirmAdminOpen(true);
                                            }}
                                            disabled={administrationForm.processing || !administrationForm.data.medication_id}
                                        >
                                            Save Administration
                                        </Button>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Administration History */}
                            <Card className={cn(can_record && medications.length > 0 ? 'lg:col-span-2' : 'lg:col-span-3')}>
                                <CardHeader>
                                    <CardTitle className="text-base">Administration History</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2 max-h-[500px] overflow-y-auto">
                                        {safeAdministrations.map((a) => (
                                            <div key={a.id} className="rounded-md border p-3">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div className="font-medium text-sm">{a.medication?.name}</div>
                                                        <div className="text-xs text-muted-foreground mt-1">
                                                            {a.administered_at ? new Date(a.administered_at).toLocaleString() : 'Not recorded'}
                                                            {a.administeredBy?.name ? ` • ${a.administeredBy.name}` : ''}
                                                            {a.dose_given ? ` • Dose: ${a.dose_given}` : ''}
                                                        </div>
                                                    </div>
                                                    <AdminStatusBadge status={a.status} />
                                                </div>
                                                {a.reason && a.status !== 'given' && (
                                                    <div className="text-xs mt-2 p-2 bg-muted rounded">Reason: {a.reason}</div>
                                                )}
                                                {a.notes && <div className="text-xs text-muted-foreground mt-2">{a.notes}</div>}
                                            </div>
                                        ))}
                                        {!administrations.length && (
                                            <div className="text-sm text-muted-foreground text-center py-8">No administrations recorded yet</div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Discrepancies */}
                        {can_controlled_view && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-amber-600" />
                                        Controlled Drug Discrepancies
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2 max-h-[300px] overflow-y-auto">
                                        {safeControlledDiscrepancies.map((d: any) => (
                                            <div key={d.id} className="rounded-md border p-3">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-medium text-sm">{d.medication?.name}</span>
                                                    <Badge variant={d.status === 'open' ? 'destructive' : 'secondary'} className="text-xs">
                                                        {d.status}
                                                    </Badge>
                                                </div>
                                                <div className="text-xs text-muted-foreground mt-1">
                                                    {d.reported_at ? new Date(d.reported_at).toLocaleString() : ''}
                                                    {d.reportedBy?.name ? ` • ${d.reportedBy.name}` : ''}
                                                </div>
                                                <div className="text-xs mt-1">
                                                    Stock: {d.on_hand_before ?? '—'} → {d.on_hand_after ?? '—'}
                                                    {d.difference !== null && <span className="text-amber-600 font-medium ml-2">Diff: {d.difference}</span>}
                                                </div>
                                                {d.reason && <div className="text-xs text-muted-foreground mt-1">Reason: {d.reason}</div>}
                                                {d.status === 'open' && can_controlled_record && (
                                                    <div className="mt-2 flex justify-end">
                                                        <Button size="sm" variant="outline" onClick={() => { setSelectedDiscId(d.id); setCloseDiscOpen(true); }}>
                                                            Close Discrepancy
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                        {!controlled_discrepancies.length && (
                                            <div className="text-sm text-muted-foreground text-center py-4">No controlled drug discrepancies</div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>
                </TabsRoot>
            </div>

            {/* Confirm Administration Dialog */}
            <Dialog open={confirmAdminOpen} onOpenChange={setConfirmAdminOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirm Administration</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <div className="grid grid-cols-2 gap-2">
                            <span className="text-muted-foreground">Medication:</span>
                            <span>{selectedMedication?.name}</span>
                            <span className="text-muted-foreground">Status:</span>
                            <span className="capitalize">{administrationForm.data.status}</span>
                            {administrationForm.data.dose_given && (
                                <><span className="text-muted-foreground">Dose:</span><span>{administrationForm.data.dose_given}</span></>
                            )}
                            {administrationForm.data.reason && (
                                <><span className="text-muted-foreground">Reason:</span><span>{administrationForm.data.reason}</span></>
                            )}
                            <span className="text-muted-foreground">Time:</span>
                            <span>{administrationForm.data.administered_at || 'Now'}</span>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmAdminOpen(false)}>Cancel</Button>
                        <Button onClick={submitAdministration} disabled={administrationForm.processing}>Confirm</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Close Discrepancy Dialog */}
            <Dialog open={closeDiscOpen} onOpenChange={setCloseDiscOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close Discrepancy</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <Label>Resolution Notes</Label>
                        <Textarea value={closeDiscForm.data.resolution_notes} onChange={(e) => closeDiscForm.setData('resolution_notes', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCloseDiscOpen(false)}>Cancel</Button>
                        <Button
                            onClick={() => {
                                if (!selectedDiscId) return;
                                closeDiscForm.post(`/clients/${client.id}/medical/controlled-discrepancies/${selectedDiscId}/close`, {
                                    preserveScroll: true,
                                    onSuccess: () => { setCloseDiscOpen(false); setSelectedDiscId(null); },
                                });
                            }}
                            disabled={closeDiscForm.processing}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
