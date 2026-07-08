import ClientSafetyRibbon, {
    type ClientSafety,
} from '@/components/client-safety-ribbon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
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
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ClipboardList,
    FileHeart,
    Heart,
    Home,
    Package,
    Pencil,
    Phone,
    Pill,
    Plus,
    Shield,
    Stethoscope,
    Syringe,
    Thermometer,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    can_record: boolean;
    can_stock: boolean;
    profile: any | null;
    medications: Array<any>;
    conditions: Array<any>;
    emergency_contacts: Array<any>;
    administrations: Array<any>;
    can_controlled_view: boolean;
    can_controlled_record: boolean;
    can_controlled_witness: boolean;
    witnesses: Array<any>;
    controlled_entries: Array<any>;
    controlled_discrepancies: Array<any>;
    med_charts?: Array<any>;
    has_open_controlled_discrepancy?: boolean;
};

export default function ClientMedical({
    client,
    can_edit,
    can_record,
    can_stock,
    profile,
    medications,
    conditions,
    emergency_contacts,
    administrations,
    can_controlled_view,
    can_controlled_record,
    can_controlled_witness,
    witnesses,
    controlled_entries,
    controlled_discrepancies,
    med_charts = [],
    has_open_controlled_discrepancy = false,
}: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const [confirmAdminOpen, setConfirmAdminOpen] = useState(false);
    const [showAddMed, setShowAddMed] = useState(false);
    const [showAddCondition, setShowAddCondition] = useState(false);
    const [showAddContact, setShowAddContact] = useState(false);
    const [showAdminForm, setShowAdminForm] = useState(false);
    const [showStockForm, setShowStockForm] = useState(false);
    const [editingProfile, setEditingProfile] = useState(false);

    // When navigating from the client profile "Manage" buttons, we pass a section.
    // This keeps the medical workflow focused instead of showing every create form at once.
    const sectionParam =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('section')
            : null;
    const [focusSection, setFocusSection] = useState<string>(
        sectionParam ?? 'all',
    );

    const profileForm = useForm({
        medical_history: profile?.medical_history || '',
        disabilities: profile?.disabilities || '',
        allergies: profile?.allergies || '',
        notes: profile?.notes || '',
        gp_name: profile?.gp_name || '',
        gp_practice: profile?.gp_practice || '',
        gp_phone: profile?.gp_phone || '',
        hospital_preference: profile?.hospital_preference || '',
        blood_type: profile?.blood_type || '',
    });

    const medForm = useForm({
        name: '',
        dosage: '',
        frequency: '',
        dose_times: '' as any,
        is_prn: false,
        controlled_drug: false,
        prn_reason: '',
        max_per_day: '',
        route: '',
        form: '',
        prescriber: '',
        pharmacy: '',
        state: 'active',
        ceased_at: '',
        ceased_reason: '',
        start_date: '',
        end_date: '',
        instructions: '',
        active: true,
    });

    const administrationForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        status: 'given',
        reason: '',
        reason_code: '',
        dose_given: '',
        administered_at: new Date().toISOString().slice(0, 16),
        scheduled_for: '',
        shift_id: '',
        witnessed_by: '',
        witness_credential: '',
        notes: '',
    });

    // NZ-standard "not given" reason codes (mirrors App\Enums\Medication\NotGivenReason).
    const notGivenReasonOptions = [
        { value: 'absent', label: 'Absent' },
        { value: 'destroyed', label: 'Destroyed' },
        { value: 'doctors_instruction', label: "Doctor's instruction" },
        { value: 'fasting', label: 'Fasting' },
        { value: 'transferred', label: 'Transferred' },
        { value: 'refused', label: 'Refused' },
        { value: 'social_leave', label: 'Social leave' },
        { value: 'hospitalised', label: 'Hospitalised' },
        { value: 'medication_unavailable', label: 'Medication unavailable' },
        { value: 'vomit_or_nausea', label: 'Vomit or nausea' },
        { value: 'self_administered', label: 'Self-administered' },
        { value: 'withheld', label: 'Withheld' },
        { value: 'other', label: 'Other' },
    ];

    const selectedMedication = medications.find(
        (m) => `${m.id}` === `${administrationForm.data.medication_id}`,
    );
    const administrationNeedsReason =
        administrationForm.data.status !== 'given' ||
        !!selectedMedication?.is_prn;

    const stockForm = useForm({
        medication_id: medications?.[0]?.id ?? '',
        on_hand: '',
        unit: '',
        reorder_level: '',
        last_counted_at: '',
        notes: '',
        reason: '',
        witnessed_by: '',
    });

    const [closeDiscOpen, setCloseDiscOpen] = useState(false);
    const [selectedDiscId, setSelectedDiscId] = useState<number | null>(null);
    const closeDiscForm = useForm({
        resolution_notes: '',
    });

    const selectedStockMedication = medications.find(
        (m) => `${m.id}` === `${stockForm.data.medication_id}`,
    );
    const conditionForm = useForm({
        label: '',
        severity: '',
        notes: '',
    });

    const contactForm = useForm({
        name: '',
        relationship: '',
        phone: '',
        email: '',
        notes: '',
    });

    const submitAdministration = () => {
        administrationForm.post(
            `/operations/clients/${client.id}/medical/medications/${administrationForm.data.medication_id}/administrations`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    administrationForm.reset(
                        'dose_given',
                        'scheduled_for',
                        'notes',
                        'reason',
                        'reason_code',
                    );
                    administrationForm.reset('witnessed_by');
                    administrationForm.reset('witness_credential');
                    setConfirmAdminOpen(false);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                {
                    title: 'Medical',
                    href: `/operations/clients/${client.id}/medical`,
                },
            ]}
        >
            <Head title={`Medical - ${name}`} />

            <PageLayout
                hero={
                    <PageHero
                        avatar={{ fallback: getInitials(name) }}
                        backHref={`/operations/clients/${client.id}`}
                        title="Medical Profile"
                        description={`Health records for ${name}`}
                        stats={[
                            {
                                label: 'Active meds',
                                value: medications.filter(
                                    (m: any) =>
                                        m.active !== false && m.state !== 'ceased',
                                ).length,
                            },
                            { label: 'Conditions', value: conditions.length },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                onClick={() =>
                                    (window.location.href = `/operations/clients/${client.id}/mar`)
                                }
                            >
                                <ClipboardList className="mr-1.5 h-3.5 w-3.5" />
                                Daily MAR
                            </Button>
                        }
                    />
                }
            >
                <ClientSafetyRibbon
                    safety={
                        (usePage().props as any).safety as
                            | ClientSafety
                            | null
                            | undefined
                    }
                />

                {has_open_controlled_discrepancy && (
                    <div className="flex items-center gap-3 rounded-xl border-2 border-status-warning/30 bg-status-warning-bg p-4">
                        <AlertTriangle className="h-6 w-6 shrink-0 text-status-warning" />
                        <div>
                            <p className="text-sm font-bold text-status-warning">
                                Open Controlled Drug Discrepancy
                            </p>
                            <p className="text-sm text-status-warning">
                                There is an open controlled-drug discrepancy for
                                this{' '}
                                {(
                                    labels?.['client.singular'] ?? 'Client'
                                ).toLowerCase()}
                                . Review and resolve before further controlled
                                stock edits (unless override is granted).
                            </p>
                        </div>
                    </div>
                )}

                {med_charts.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <ClipboardList className="h-4 w-4" />
                                </div>
                                Medication Chart (Source of Truth)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {med_charts.map((d: any) => (
                                <div
                                    key={d.id}
                                    className="flex items-center justify-between rounded-lg border p-3"
                                >
                                    <div>
                                        <div className="text-sm font-medium">
                                            {d.title}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {d.version
                                                ? `v${d.version} \u2022 `
                                                : ''}
                                            {d.effective_date
                                                ? `Effective: ${new Date(d.effective_date).toLocaleDateString()}`
                                                : ''}
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            (window.location.href = `/operations/clients/${client.id}/documents/${d.id}/download`)
                                        }
                                    >
                                        Download
                                    </Button>
                                </div>
                            ))}
                            <div className="text-xs text-muted-foreground">
                                To upload/update charts, use the Documents tab
                                (category: Medication chart).
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Section tabs */}
                <div className="overflow-x-auto border-b">
                    <div className="flex w-max items-center gap-1">
                        {[
                            { key: 'all', label: 'Overview', icon: Activity },
                            {
                                key: 'profile',
                                label: 'Medical Profile',
                                icon: FileHeart,
                            },
                            {
                                key: 'medications',
                                label: 'Medications',
                                icon: Pill,
                                count: medications.length,
                            },
                            {
                                key: 'administrations',
                                label: 'Administrations',
                                icon: Syringe,
                                count: administrations.length,
                            },
                            { key: 'stock', label: 'Stock', icon: Package },
                            ...(can_controlled_view
                                ? [
                                      {
                                          key: 'controlled_drugs',
                                          label: 'Controlled Drugs',
                                          icon: Shield,
                                          count: controlled_entries.length,
                                      },
                                  ]
                                : []),
                            {
                                key: 'conditions',
                                label: 'Conditions',
                                icon: Thermometer,
                                count: conditions.length,
                            },
                            {
                                key: 'emergency_contacts',
                                label: 'Emergency Contacts',
                                icon: Phone,
                                count: emergency_contacts.length,
                            },
                        ].map((s) => {
                            const Icon = s.icon;
                            const isActive = focusSection === s.key;
                            return (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    key={s.key}
                                    onClick={() => setFocusSection(s.key)}
                                    className={`h-auto rounded-none border-b-2 px-3 py-2.5 text-sm font-medium ${
                                        isActive
                                            ? 'border-primary text-primary'
                                            : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                                    }`}
                                >
                                    <Icon className="h-3.5 w-3.5" />
                                    {s.label}
                                    {'count' in s && (s as any).count > 0 && (
                                        <span
                                            className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] leading-none font-semibold ${
                                                isActive
                                                    ? 'bg-primary/10 text-primary'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {(s as any).count}
                                        </span>
                                    )}
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {/* ── Dashboard Overview (visible only in Overview tab) ── */}
                {focusSection === 'all' && (
                    <div className="space-y-4">
                        {/* KPI Row */}
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <div className="rounded-xl border bg-status-critical-bg p-4">
                                <p className="text-[10px] font-semibold tracking-wider text-status-critical uppercase">
                                    Allergies
                                </p>
                                <p className="mt-1 text-lg font-bold text-status-critical dark:text-status-critical">
                                    {profile?.allergies || 'None recorded'}
                                </p>
                            </div>
                            <div className="rounded-xl border bg-primary/10 p-4">
                                <p className="text-[10px] font-semibold tracking-wider text-primary uppercase">
                                    Active Medications
                                </p>
                                <p className="mt-1 text-lg font-bold text-primary dark:text-primary/70">
                                    {
                                        medications.filter(
                                            (m: any) =>
                                                m.active !== false &&
                                                m.state !== 'ceased',
                                        ).length
                                    }
                                </p>
                                {medications.some(
                                    (m: any) =>
                                        m.controlled_drug ||
                                        m.is_controlled_drug,
                                ) && (
                                    <p className="mt-0.5 text-[10px] text-status-warning">
                                        {
                                            medications.filter(
                                                (m: any) =>
                                                    m.controlled_drug ||
                                                    m.is_controlled_drug,
                                            ).length
                                        }{' '}
                                        controlled
                                    </p>
                                )}
                            </div>
                            <div className="rounded-xl border bg-status-info-bg p-4">
                                <p className="text-[10px] font-semibold tracking-wider text-status-info uppercase">
                                    Conditions
                                </p>
                                <p className="mt-1 text-lg font-bold text-status-info dark:text-status-info">
                                    {conditions.length || 'None'}
                                </p>
                            </div>
                            <div className="rounded-xl border bg-status-success-bg p-4">
                                <p className="text-[10px] font-semibold tracking-wider text-status-success uppercase">
                                    Emergency Contacts
                                </p>
                                <p className="mt-1 text-lg font-bold text-status-success dark:text-status-success">
                                    {emergency_contacts.length || 'None'}
                                </p>
                            </div>
                        </div>

                        {/* GP + Blood Type + Hospital row */}
                        {(profile?.gp_name ||
                            profile?.gp_practice ||
                            profile?.blood_type ||
                            profile?.hospital_preference) && (
                            <Card>
                                <CardContent className="p-4">
                                    <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                                        {(profile?.gp_name ||
                                            profile?.gp_practice) && (
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                                                    <Stethoscope className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        GP / Doctor
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {profile.gp_name || '—'}
                                                    </p>
                                                    {profile.gp_practice && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {
                                                                profile.gp_practice
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                        {profile?.gp_phone && (
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-info-bg text-status-info">
                                                    <Phone className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        GP Phone
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {profile.gp_phone}
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                        {profile?.hospital_preference && (
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                                                    <Home className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Hospital Preference
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {
                                                            profile.hospital_preference
                                                        }
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                        {profile?.blood_type && (
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
                                                    <Heart className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Blood Type
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {profile.blood_type}
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Quick lists: Medications + Conditions side by side */}
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {/* Active Medications List */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center justify-between text-sm">
                                        <span className="flex items-center gap-2">
                                            <Pill className="h-4 w-4 text-primary" />
                                            Active Medications
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-xs"
                                            onClick={() =>
                                                setFocusSection('medications')
                                            }
                                        >
                                            View all
                                        </Button>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {medications.filter(
                                        (m: any) =>
                                            m.active !== false &&
                                            m.state !== 'ceased',
                                    ).length > 0 ? (
                                        <div className="space-y-2">
                                            {medications
                                                .filter(
                                                    (m: any) =>
                                                        m.active !== false &&
                                                        m.state !== 'ceased',
                                                )
                                                .map((m: any) => (
                                                    <div
                                                        key={m.id}
                                                        className="flex items-center justify-between rounded-lg border p-2.5"
                                                    >
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium">
                                                                    {m.name}
                                                                </span>
                                                                {m.is_prn && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[9px]"
                                                                    >
                                                                        PRN
                                                                    </Badge>
                                                                )}
                                                                {(m.controlled_drug ||
                                                                    m.is_controlled_drug) && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 bg-status-warning-bg text-[9px] text-status-warning"
                                                                    >
                                                                        CD
                                                                    </Badge>
                                                                )}
                                                                {(m.high_risk ||
                                                                    m.is_high_risk) && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-critical/30 bg-status-critical-bg text-[9px] text-status-critical"
                                                                    >
                                                                        High
                                                                        Risk
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-muted-foreground">
                                                                {[
                                                                    m.dosage,
                                                                    m.frequency,
                                                                    m.route,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' · ',
                                                                    )}
                                                            </p>
                                                        </div>
                                                    </div>
                                                ))}
                                        </div>
                                    ) : (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            No active medications
                                        </p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Conditions + Emergency Contacts */}
                            <div className="space-y-4">
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-sm">
                                            <span className="flex items-center gap-2">
                                                <Thermometer className="h-4 w-4 text-status-info" />
                                                Conditions
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-xs"
                                                onClick={() =>
                                                    setFocusSection(
                                                        'conditions',
                                                    )
                                                }
                                            >
                                                Manage
                                            </Button>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {conditions.length > 0 ? (
                                            <div className="space-y-1.5">
                                                {conditions.map((c: any) => (
                                                    <div
                                                        key={c.id}
                                                        className="flex items-center justify-between rounded-lg border p-2.5"
                                                    >
                                                        <span className="text-sm font-medium">
                                                            {c.label}
                                                        </span>
                                                        {c.severity && (
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[9px] ${c.severity === 'high' ? 'border-status-critical/30 bg-status-critical-bg text-status-critical' : c.severity === 'medium' ? 'border-status-warning/30 bg-status-warning-bg text-status-warning' : ''}`}
                                                            >
                                                                {c.severity}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No conditions recorded
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-sm">
                                            <span className="flex items-center gap-2">
                                                <Phone className="h-4 w-4 text-status-success" />
                                                Emergency Contacts
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-xs"
                                                onClick={() =>
                                                    setFocusSection(
                                                        'emergency_contacts',
                                                    )
                                                }
                                            >
                                                Manage
                                            </Button>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {emergency_contacts.length > 0 ? (
                                            <div className="space-y-1.5">
                                                {emergency_contacts.map(
                                                    (ec: any) => (
                                                        <div
                                                            key={ec.id}
                                                            className="flex items-center justify-between rounded-lg border p-2.5"
                                                        >
                                                            <div>
                                                                <span className="text-sm font-medium">
                                                                    {ec.name}
                                                                </span>
                                                                {ec.relationship && (
                                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                                        {
                                                                            ec.relationship
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {ec.phone && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {ec.phone}
                                                                </span>
                                                            )}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        ) : (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No emergency contacts
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        {/* Medical History + Disabilities + Notes (read-only summary) */}
                        {(profile?.medical_history ||
                            profile?.disabilities) && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center justify-between text-sm">
                                        <span className="flex items-center gap-2">
                                            <FileHeart className="h-4 w-4 text-status-critical" />
                                            Medical History
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-xs"
                                            onClick={() =>
                                                setFocusSection('profile')
                                            }
                                        >
                                            Edit
                                        </Button>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        {profile?.medical_history && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    History
                                                </p>
                                                <p className="text-sm leading-relaxed">
                                                    {profile.medical_history}
                                                </p>
                                            </div>
                                        )}
                                        {profile?.disabilities && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Disabilities
                                                </p>
                                                <p className="text-sm leading-relaxed">
                                                    {profile.disabilities}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    {profile?.notes && (
                                        <div className="mt-3 rounded-lg bg-muted/50 p-3">
                                            <p className="text-xs text-muted-foreground">
                                                {profile.notes}
                                            </p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                <div
                    className={cn(
                        'grid auto-rows-fr grid-cols-1 gap-4 md:grid-cols-2',
                        ![
                            'profile',
                            'medications',
                            'conditions',
                            'emergency_contacts',
                        ].includes(focusSection) && 'hidden',
                    )}
                >
                    {/* Medical Profile Card */}
                    <Card
                        className={cn(focusSection !== 'profile' && 'hidden')}
                    >
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
                                    <FileHeart className="h-4 w-4" />
                                </div>
                                Medical Profile
                            </CardTitle>
                            {can_edit && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setEditingProfile(!editingProfile)
                                    }
                                >
                                    <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                    {editingProfile ? 'View' : 'Edit'}
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {/* Read-only view */}
                            {!editingProfile && (
                                <div className="space-y-4">
                                    {/* GP Info */}
                                    {(profile?.gp_name ||
                                        profile?.gp_practice ||
                                        profile?.gp_phone) && (
                                        <div className="rounded-xl border border-status-success/30 bg-status-success-bg p-4">
                                            <div className="mb-2 flex items-center gap-2">
                                                <Stethoscope className="h-4 w-4 text-status-success" />
                                                <span className="text-sm font-semibold text-status-success">
                                                    GP / Primary Care
                                                </span>
                                            </div>
                                            <div className="grid gap-2 text-sm sm:grid-cols-3">
                                                {profile.gp_name && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Doctor
                                                        </span>
                                                        <p className="font-medium">
                                                            {profile.gp_name}
                                                        </p>
                                                    </div>
                                                )}
                                                {profile.gp_practice && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Practice
                                                        </span>
                                                        <p className="font-medium">
                                                            {
                                                                profile.gp_practice
                                                            }
                                                        </p>
                                                    </div>
                                                )}
                                                {profile.gp_phone && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Phone
                                                        </span>
                                                        <p className="font-medium">
                                                            {profile.gp_phone}
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {/* Read-only fields */}
                                    <div className="grid gap-4 md:grid-cols-2">
                                        {profile?.medical_history && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Medical History
                                                </p>
                                                <p className="text-sm leading-relaxed">
                                                    {profile.medical_history}
                                                </p>
                                            </div>
                                        )}
                                        {profile?.disabilities && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Disabilities
                                                </p>
                                                <p className="text-sm leading-relaxed">
                                                    {profile.disabilities}
                                                </p>
                                            </div>
                                        )}
                                        {profile?.allergies && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-status-critical uppercase">
                                                    Allergies
                                                </p>
                                                <p className="text-sm font-medium text-status-critical">
                                                    {profile.allergies}
                                                </p>
                                            </div>
                                        )}
                                        {profile?.blood_type && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Blood Type
                                                </p>
                                                <p className="text-sm font-medium">
                                                    {profile.blood_type}
                                                </p>
                                            </div>
                                        )}
                                        {profile?.hospital_preference && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Hospital Preference
                                                </p>
                                                <p className="text-sm">
                                                    {
                                                        profile.hospital_preference
                                                    }
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    {profile?.notes && (
                                        <div className="rounded-lg bg-muted/50 p-3">
                                            <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                Notes
                                            </p>
                                            <p className="text-sm">
                                                {profile.notes}
                                            </p>
                                        </div>
                                    )}
                                    {!profile?.medical_history &&
                                        !profile?.disabilities &&
                                        !profile?.allergies && (
                                            <p className="py-6 text-center text-sm text-muted-foreground">
                                                No medical profile data recorded
                                                yet. Click Edit to add
                                                information.
                                            </p>
                                        )}
                                </div>
                            )}

                            {/* Edit form */}
                            {editingProfile && (
                                <div className="space-y-3">
                                    {/* GP Information highlight card */}
                                    {(profile?.gp_name ||
                                        profile?.gp_practice ||
                                        profile?.gp_phone) && (
                                        <div className="mb-4 rounded-xl border border-status-success/30 bg-status-success-bg p-4">
                                            <div className="mb-2 flex items-center gap-2">
                                                <Stethoscope className="h-4 w-4 text-status-success" />
                                                <span className="text-sm font-semibold text-status-success">
                                                    GP / Primary Care
                                                </span>
                                            </div>
                                            <div className="grid gap-2 text-sm sm:grid-cols-3">
                                                {profile.gp_name && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Doctor
                                                        </span>
                                                        <p className="font-medium">
                                                            {profile.gp_name}
                                                        </p>
                                                    </div>
                                                )}
                                                {profile.gp_practice && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Practice
                                                        </span>
                                                        <p className="font-medium">
                                                            {
                                                                profile.gp_practice
                                                            }
                                                        </p>
                                                    </div>
                                                )}
                                                {profile.gp_phone && (
                                                    <div>
                                                        <span className="text-xs text-muted-foreground">
                                                            Phone
                                                        </span>
                                                        <p className="font-medium">
                                                            {profile.gp_phone}
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    <div>
                                        <Label>Medical history</Label>
                                        <Textarea
                                            value={
                                                profileForm.data.medical_history
                                            }
                                            onChange={(e) =>
                                                profileForm.setData(
                                                    'medical_history',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={!can_edit}
                                        />
                                    </div>
                                    <div>
                                        <Label>Disabilities</Label>
                                        <Textarea
                                            value={
                                                profileForm.data.disabilities
                                            }
                                            onChange={(e) =>
                                                profileForm.setData(
                                                    'disabilities',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={!can_edit}
                                        />
                                    </div>
                                    <div>
                                        <Label>Allergies</Label>
                                        <Textarea
                                            value={profileForm.data.allergies}
                                            onChange={(e) =>
                                                profileForm.setData(
                                                    'allergies',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={!can_edit}
                                        />
                                    </div>
                                    <div>
                                        <Label>Notes</Label>
                                        <Textarea
                                            value={profileForm.data.notes}
                                            onChange={(e) =>
                                                profileForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={!can_edit}
                                        />
                                    </div>

                                    <Separator />

                                    {/* GP / Hospital / Blood Type fields */}
                                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <Label>GP name</Label>
                                            <Input
                                                value={profileForm.data.gp_name}
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'gp_name',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!can_edit}
                                                placeholder="Dr. Smith"
                                            />
                                        </div>
                                        <div>
                                            <Label>GP practice</Label>
                                            <Input
                                                value={
                                                    profileForm.data.gp_practice
                                                }
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'gp_practice',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!can_edit}
                                                placeholder="Riverside Medical Centre"
                                            />
                                        </div>
                                        <div>
                                            <Label>GP phone</Label>
                                            <Input
                                                value={
                                                    profileForm.data.gp_phone
                                                }
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'gp_phone',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!can_edit}
                                                placeholder="0123 456 7890"
                                            />
                                        </div>
                                        <div>
                                            <Label>Hospital preference</Label>
                                            <Input
                                                value={
                                                    profileForm.data
                                                        .hospital_preference
                                                }
                                                onChange={(e) =>
                                                    profileForm.setData(
                                                        'hospital_preference',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!can_edit}
                                                placeholder="e.g. Royal Infirmary"
                                            />
                                        </div>
                                        <div>
                                            <Label>Blood type</Label>
                                            <Select
                                                value={
                                                    profileForm.data.blood_type
                                                }
                                                onValueChange={(v) =>
                                                    profileForm.setData(
                                                        'blood_type',
                                                        v,
                                                    )
                                                }
                                                disabled={!can_edit}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select blood type" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {[
                                                        'A+',
                                                        'A-',
                                                        'B+',
                                                        'B-',
                                                        'AB+',
                                                        'AB-',
                                                        'O+',
                                                        'O-',
                                                        'Unknown',
                                                    ].map((bt) => (
                                                        <SelectItem
                                                            key={bt}
                                                            value={bt}
                                                        >
                                                            {bt}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    {can_edit && (
                                        <Button
                                            className="bg-primary hover:bg-primary"
                                            onClick={() =>
                                                profileForm.put(
                                                    `/operations/clients/${client.id}/medical/profile`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                            disabled={profileForm.processing}
                                        >
                                            Save profile
                                        </Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Medications Card */}
                    <Card
                        className={cn(
                            focusSection !== 'medications' && 'hidden',
                        )}
                    >
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Pill className="h-4 w-4" />
                                </div>
                                Medications
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {can_edit && !showAddMed && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => setShowAddMed(true)}
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    Add Medication
                                </Button>
                            )}
                            {can_edit && showAddMed && (
                                <div className="bg-primary/10 rounded-xl border border-dashed border-primary p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm font-medium text-primary">
                                            <Plus className="h-4 w-4" />
                                            Add medication
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setShowAddMed(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <Label>Name</Label>
                                            <Input
                                                value={medForm.data.name}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Dosage</Label>
                                            <Input
                                                value={medForm.data.dosage}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'dosage',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Frequency</Label>
                                            <Input
                                                value={medForm.data.frequency}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'frequency',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="flex items-center gap-2 pt-6">
                                            <Checkbox
                                                checked={!!medForm.data.is_prn}
                                                onCheckedChange={(v) =>
                                                    medForm.setData(
                                                        'is_prn',
                                                        !!v,
                                                    )
                                                }
                                            />
                                            <Label className="!mt-0">
                                                PRN (as needed)
                                            </Label>
                                        </div>
                                        <div className="flex items-center gap-2 pt-6">
                                            <Checkbox
                                                checked={
                                                    !!medForm.data
                                                        .controlled_drug
                                                }
                                                onCheckedChange={(v) =>
                                                    medForm.setData(
                                                        'controlled_drug',
                                                        !!v,
                                                    )
                                                }
                                            />
                                            <Label className="!mt-0">
                                                Controlled drug (double-sign
                                                required)
                                            </Label>
                                        </div>
                                        {medForm.data.is_prn && (
                                            <>
                                                <div>
                                                    <Label>PRN reason</Label>
                                                    <Input
                                                        value={
                                                            medForm.data
                                                                .prn_reason
                                                        }
                                                        onChange={(e) =>
                                                            medForm.setData(
                                                                'prn_reason',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Max per day</Label>
                                                    <Input
                                                        value={
                                                            medForm.data
                                                                .max_per_day
                                                        }
                                                        onChange={(e) =>
                                                            medForm.setData(
                                                                'max_per_day',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </>
                                        )}
                                        <div>
                                            <Label>Route</Label>
                                            <Input
                                                value={medForm.data.route}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'route',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Prescriber</Label>
                                            <Input
                                                value={medForm.data.prescriber}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'prescriber',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Pharmacy</Label>
                                            <Input
                                                value={medForm.data.pharmacy}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'pharmacy',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>
                                                Form (tablet/liquid/patch)
                                            </Label>
                                            <Input
                                                value={medForm.data.form}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'form',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>
                                                Dose times (HH:MM, comma
                                                separated)
                                            </Label>
                                            <Input
                                                value={
                                                    medForm.data
                                                        .dose_times as any
                                                }
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'dose_times',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. 08:00, 12:00, 18:00"
                                            />
                                        </div>
                                        <div>
                                            <Label>State</Label>
                                            <Select
                                                value={medForm.data.state}
                                                onValueChange={(v) =>
                                                    medForm.setData('state', v)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select state" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="active">
                                                        Active
                                                    </SelectItem>
                                                    <SelectItem value="paused">
                                                        Paused
                                                    </SelectItem>
                                                    <SelectItem value="ceased">
                                                        Ceased
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {medForm.data.state === 'ceased' && (
                                            <>
                                                <div>
                                                    <Label>Ceased date</Label>
                                                    <Input
                                                        type="date"
                                                        value={
                                                            medForm.data
                                                                .ceased_at
                                                        }
                                                        onChange={(e) =>
                                                            medForm.setData(
                                                                'ceased_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Ceased reason</Label>
                                                    <Input
                                                        value={
                                                            medForm.data
                                                                .ceased_reason
                                                        }
                                                        onChange={(e) =>
                                                            medForm.setData(
                                                                'ceased_reason',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </>
                                        )}
                                        <div>
                                            <Label>Start date</Label>
                                            <Input
                                                type="date"
                                                value={medForm.data.start_date}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'start_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>End date</Label>
                                            <Input
                                                type="date"
                                                value={medForm.data.end_date}
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'end_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label>Instructions</Label>
                                            <Textarea
                                                value={
                                                    medForm.data.instructions
                                                }
                                                onChange={(e) =>
                                                    medForm.setData(
                                                        'instructions',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="flex items-center gap-2 pt-6">
                                            <Checkbox
                                                checked={!!medForm.data.active}
                                                onCheckedChange={(v) =>
                                                    medForm.setData(
                                                        'active',
                                                        !!v,
                                                    )
                                                }
                                            />
                                            <Label className="!mt-0">
                                                Active
                                            </Label>
                                        </div>
                                    </div>
                                    <div className="mt-3">
                                        <Button
                                            className="bg-primary hover:bg-primary"
                                            onClick={() => {
                                                // Inertia's useForm().transform() does not always support chaining in all versions.
                                                // Normalize dose_times without relying on chained calls.
                                                const dt =
                                                    typeof (medForm.data as any)
                                                        .dose_times === 'string'
                                                        ? (
                                                              medForm.data as any
                                                          ).dose_times
                                                              .split(',')
                                                              .map(
                                                                  (s: string) =>
                                                                      s.trim(),
                                                              )
                                                              .filter(Boolean)
                                                        : (medForm.data as any)
                                                              .dose_times;
                                                medForm.setData(
                                                    'dose_times',
                                                    dt as any,
                                                );
                                                medForm.post(
                                                    `/operations/clients/${client.id}/medical/medications`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            medForm.reset(),
                                                    },
                                                );
                                            }}
                                            disabled={
                                                medForm.processing ||
                                                !medForm.data.name.trim()
                                            }
                                        >
                                            <Plus className="mr-1 h-4 w-4" />
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            )}

                            <Separator />

                            {/* Medication list */}
                            <div className="space-y-2">
                                {medications.map((m) => {
                                    const isActive =
                                        m.state === 'active' || m.active;
                                    const isOverdue =
                                        m.end_date &&
                                        new Date(m.end_date) < new Date();
                                    return (
                                        <div
                                            key={m.id}
                                            className={cn(
                                                'rounded-lg border p-4 transition-colors',
                                                isActive
                                                    ? 'border-l-4 border-l-violet-400 bg-white'
                                                    : 'border-l-4 border-l-slate-200 bg-muted/50',
                                            )}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex items-center gap-2">
                                                    <Pill className="h-4 w-4 text-primary" />
                                                    <span className="text-sm font-semibold">
                                                        {m.name}
                                                    </span>
                                                    {m.controlled_drug && (
                                                        <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[10px] font-medium text-status-warning">
                                                            Controlled
                                                        </span>
                                                    )}
                                                    {m.is_prn && (
                                                        <span className="rounded-full bg-status-info-bg px-2 py-0.5 text-[10px] font-medium text-status-info">
                                                            PRN
                                                        </span>
                                                    )}
                                                    {!isActive && (
                                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                            {m.state ||
                                                                'Inactive'}
                                                        </span>
                                                    )}
                                                </div>
                                                {can_edit && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                                        onClick={() =>
                                                            medForm.delete(
                                                                `/operations/clients/${client.id}/medical/medications/${m.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                {m.dosage && (
                                                    <span>
                                                        Dosage:{' '}
                                                        <span className="font-medium text-foreground">
                                                            {m.dosage}
                                                        </span>
                                                    </span>
                                                )}
                                                {m.frequency && (
                                                    <span>
                                                        Frequency:{' '}
                                                        <span className="font-medium text-foreground">
                                                            {m.frequency}
                                                        </span>
                                                    </span>
                                                )}
                                                {m.route && (
                                                    <span>
                                                        Route:{' '}
                                                        <span className="font-medium text-foreground">
                                                            {m.route}
                                                        </span>
                                                    </span>
                                                )}
                                            </div>
                                            {m.instructions && (
                                                <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                    {m.instructions}
                                                </div>
                                            )}
                                            {isOverdue && (
                                                <div className="mt-2 flex items-center gap-1 text-xs text-status-critical">
                                                    <AlertTriangle className="h-3 w-3" />
                                                    Review overdue (end date:{' '}
                                                    {new Date(
                                                        m.end_date,
                                                    ).toLocaleDateString()}
                                                    )
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                                {!medications.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No medications listed.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Conditions Card */}
                    <Card
                        className={cn(
                            focusSection !== 'conditions' && 'hidden',
                        )}
                    >
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                                    <Thermometer className="h-4 w-4" />
                                </div>
                                Conditions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {can_edit && !showAddCondition && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => setShowAddCondition(true)}
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    Add Condition
                                </Button>
                            )}
                            {can_edit && showAddCondition && (
                                <div className="rounded-xl border border-dashed border-status-warning/30 bg-status-warning-bg p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm font-medium text-status-warning">
                                            <Plus className="h-4 w-4" />
                                            Add condition
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setShowAddCondition(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <Label>Label</Label>
                                            <Input
                                                value={conditionForm.data.label}
                                                onChange={(e) =>
                                                    conditionForm.setData(
                                                        'label',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Severity</Label>
                                            <Input
                                                value={
                                                    conditionForm.data.severity
                                                }
                                                onChange={(e) =>
                                                    conditionForm.setData(
                                                        'severity',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="mild / moderate / severe"
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label>Notes</Label>
                                            <Textarea
                                                value={conditionForm.data.notes}
                                                onChange={(e) =>
                                                    conditionForm.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3">
                                        <Button
                                            className="bg-status-warning hover:bg-status-warning"
                                            onClick={() =>
                                                conditionForm.post(
                                                    `/operations/clients/${client.id}/medical/conditions`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            conditionForm.reset(),
                                                    },
                                                )
                                            }
                                            disabled={
                                                conditionForm.processing ||
                                                !conditionForm.data.label.trim()
                                            }
                                        >
                                            <Plus className="mr-1 h-4 w-4" />
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            )}

                            <Separator />

                            <div className="space-y-2">
                                {conditions.map((c) => (
                                    <div
                                        key={c.id}
                                        className="rounded-lg border border-l-4 border-l-amber-300 p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex items-center gap-2">
                                                <Thermometer className="h-4 w-4 text-status-warning" />
                                                <span className="text-sm font-semibold">
                                                    {c.label}
                                                </span>
                                                {c.severity && (
                                                    <span
                                                        className={cn(
                                                            'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                                            c.severity ===
                                                                'severe'
                                                                ? 'bg-status-critical-bg text-status-critical'
                                                                : c.severity ===
                                                                    'moderate'
                                                                  ? 'bg-status-warning-bg text-status-warning'
                                                                  : 'bg-status-success-bg text-status-success',
                                                        )}
                                                    >
                                                        {c.severity}
                                                    </span>
                                                )}
                                            </div>
                                            {can_edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                                    onClick={() =>
                                                        conditionForm.delete(
                                                            `/operations/clients/${client.id}/medical/conditions/${c.id}`,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                        {c.notes && (
                                            <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                {c.notes}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {!conditions.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No conditions listed.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Emergency Contacts Card */}
                    <Card
                        className={cn(
                            focusSection !== 'emergency_contacts' && 'hidden',
                        )}
                    >
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-info-bg text-status-info">
                                    <Phone className="h-4 w-4" />
                                </div>
                                Emergency Contacts
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {can_edit && !showAddContact && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => setShowAddContact(true)}
                                >
                                    <Plus className="h-3.5 w-3.5" />
                                    Add Contact
                                </Button>
                            )}
                            {can_edit && showAddContact && (
                                <div className="rounded-xl border border-dashed border-status-info/30 bg-status-info-bg p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm font-medium text-status-info">
                                            <Plus className="h-4 w-4" />
                                            Add emergency contact
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setShowAddContact(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <Label>Name</Label>
                                            <Input
                                                value={contactForm.data.name}
                                                onChange={(e) =>
                                                    contactForm.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Relationship</Label>
                                            <Input
                                                value={
                                                    contactForm.data
                                                        .relationship
                                                }
                                                onChange={(e) =>
                                                    contactForm.setData(
                                                        'relationship',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Phone</Label>
                                            <Input
                                                value={contactForm.data.phone}
                                                onChange={(e) =>
                                                    contactForm.setData(
                                                        'phone',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label>Email</Label>
                                            <Input
                                                value={contactForm.data.email}
                                                onChange={(e) =>
                                                    contactForm.setData(
                                                        'email',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label>Notes</Label>
                                            <Textarea
                                                value={contactForm.data.notes}
                                                onChange={(e) =>
                                                    contactForm.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3">
                                        <Button
                                            className="bg-status-info hover:bg-status-info"
                                            onClick={() =>
                                                contactForm.post(
                                                    `/operations/clients/${client.id}/medical/emergency-contacts`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            contactForm.reset(),
                                                    },
                                                )
                                            }
                                            disabled={
                                                contactForm.processing ||
                                                !contactForm.data.name.trim()
                                            }
                                        >
                                            <Plus className="mr-1 h-4 w-4" />
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            )}

                            <Separator />

                            <div className="space-y-2">
                                {emergency_contacts.map((ec, idx) => {
                                    const initials = ec.name
                                        ? ec.name
                                              .split(' ')
                                              .map((w: string) => w[0])
                                              .join('')
                                              .toUpperCase()
                                              .slice(0, 2)
                                        : '??';
                                    return (
                                        <div
                                            key={ec.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-info-bg text-sm font-bold text-status-info">
                                                    {initials}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-semibold">
                                                            {ec.name}
                                                        </span>
                                                        {ec.relationship && (
                                                            <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                                {
                                                                    ec.relationship
                                                                }
                                                            </span>
                                                        )}
                                                        {ec.authorised_for_health_info && (
                                                            <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[10px] font-medium text-status-success">
                                                                <Shield className="mr-0.5 inline h-3 w-3" />
                                                                Health info
                                                                authorised
                                                            </span>
                                                        )}
                                                        {ec.contact_order && (
                                                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                                                                #
                                                                {
                                                                    ec.contact_order
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                                                        {ec.phone && (
                                                            <span className="flex items-center gap-1">
                                                                <Phone className="h-3 w-3" />
                                                                {ec.phone}
                                                            </span>
                                                        )}
                                                        {ec.email && (
                                                            <span>
                                                                {ec.email}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {ec.notes && (
                                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                            {ec.notes}
                                                        </div>
                                                    )}
                                                </div>
                                                {can_edit && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="shrink-0 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                                        onClick={() =>
                                                            contactForm.delete(
                                                                `/operations/clients/${client.id}/medical/emergency-contacts/${ec.id}`,
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                                {!emergency_contacts.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No emergency contacts listed.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Administrations tab */}
                <div
                    className={cn(
                        'space-y-4',
                        focusSection !== 'administrations' && 'hidden',
                    )}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                                    <Syringe className="h-4 w-4" />
                                </div>
                                Medication Administration (MAR)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {can_record &&
                                medications.length > 0 &&
                                !showAdminForm && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5"
                                            onClick={() =>
                                                setShowAdminForm(true)
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            Record Administration
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                (window.location.href = `/operations/clients/${client.id}/mar`)
                                            }
                                        >
                                            <ClipboardList className="mr-1.5 h-3.5 w-3.5" />
                                            Open Daily MAR
                                        </Button>
                                    </div>
                                )}
                            {can_record &&
                                medications.length > 0 &&
                                showAdminForm && (
                                    <div className="rounded-xl border border-dashed border-status-success/30 bg-status-success-bg p-4">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2 text-sm font-medium text-status-success">
                                                <Plus className="h-4 w-4" />
                                                Record administration
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setShowAdminForm(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                            <div className="md:col-span-2">
                                                <Label>Medication</Label>
                                                <Select
                                                    value={`${administrationForm.data.medication_id}`}
                                                    onValueChange={(v) =>
                                                        administrationForm.setData(
                                                            'medication_id',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select medication" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {medications.map(
                                                            (m) => (
                                                                <SelectItem
                                                                    key={m.id}
                                                                    value={`${m.id}`}
                                                                >
                                                                    {m.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div>
                                                <Label>Status</Label>
                                                <Select
                                                    value={
                                                        administrationForm.data
                                                            .status
                                                    }
                                                    onValueChange={(v) =>
                                                        administrationForm.setData(
                                                            'status',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="given">
                                                            Given
                                                        </SelectItem>
                                                        <SelectItem value="refused">
                                                            Refused
                                                        </SelectItem>
                                                        <SelectItem value="missed">
                                                            Missed
                                                        </SelectItem>
                                                        <SelectItem value="withheld">
                                                            Withheld
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {administrationForm.data.status !==
                                                'given' && (
                                                <div className="md:col-span-2">
                                                    <Label>
                                                        Reason not given
                                                        (required)
                                                    </Label>
                                                    <Select
                                                        value={
                                                            administrationForm
                                                                .data.reason_code
                                                        }
                                                        onValueChange={(v) =>
                                                            administrationForm.setData(
                                                                'reason_code',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select a reason..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {notGivenReasonOptions.map(
                                                                (opt) => (
                                                                    <SelectItem
                                                                        key={
                                                                            opt.value
                                                                        }
                                                                        value={
                                                                            opt.value
                                                                        }
                                                                    >
                                                                        {
                                                                            opt.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {administrationForm.errors
                                                        .reason_code && (
                                                        <div className="mt-1 text-xs text-status-critical">
                                                            {
                                                                administrationForm
                                                                    .errors
                                                                    .reason_code
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {administrationNeedsReason && (
                                                <div className="md:col-span-2">
                                                    <Label>
                                                        {selectedMedication?.is_prn
                                                            ? 'Indication (required for PRN)'
                                                            : 'Additional detail (optional)'}
                                                    </Label>
                                                    <Input
                                                        value={
                                                            administrationForm
                                                                .data.reason
                                                        }
                                                        onChange={(e) =>
                                                            administrationForm.setData(
                                                                'reason',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder={
                                                            selectedMedication?.is_prn
                                                                ? 'e.g. headache, anxiety, pain'
                                                                : 'e.g. client refused, clinical hold, unavailable'
                                                        }
                                                    />
                                                    {administrationForm.errors
                                                        .reason && (
                                                        <div className="mt-1 text-xs text-status-critical">
                                                            {
                                                                administrationForm
                                                                    .errors
                                                                    .reason
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {selectedMedication?.controlled_drug &&
                                                administrationForm.data
                                                    .status === 'given' && (
                                                    <div className="md:col-span-2">
                                                        <Label>
                                                            Witness (required)
                                                        </Label>
                                                        <Select
                                                            value={`${administrationForm.data.witnessed_by}`}
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                administrationForm.setData(
                                                                    'witnessed_by',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select witness" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {witnesses.map(
                                                                    (
                                                                        w: any,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                w.id
                                                                            }
                                                                            value={`${w.id}`}
                                                                        >
                                                                            {
                                                                                w.name
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        {administrationForm
                                                            .errors
                                                            .witnessed_by && (
                                                            <div className="mt-1 text-xs text-status-critical">
                                                                {
                                                                    administrationForm
                                                                        .errors
                                                                        .witnessed_by
                                                                }
                                                            </div>
                                                        )}
                                                        <div className="mt-2">
                                                            <Label>
                                                                Witness password
                                                                / PIN (required)
                                                            </Label>
                                                            <Input
                                                                type="password"
                                                                value={
                                                                    administrationForm
                                                                        .data
                                                                        .witness_credential
                                                                }
                                                                onChange={(e) =>
                                                                    administrationForm.setData(
                                                                        'witness_credential',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="The second checker authenticates"
                                                            />
                                                            {administrationForm
                                                                .errors
                                                                .witness_credential && (
                                                                <div className="mt-1 text-xs text-status-critical">
                                                                    {
                                                                        administrationForm
                                                                            .errors
                                                                            .witness_credential
                                                                    }
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}

                                            <div>
                                                <Label>Dose given</Label>
                                                <Input
                                                    value={
                                                        administrationForm.data
                                                            .dose_given
                                                    }
                                                    onChange={(e) =>
                                                        administrationForm.setData(
                                                            'dose_given',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>

                                            <div>
                                                <Label>Administered at</Label>
                                                <Input
                                                    type="datetime-local"
                                                    value={
                                                        administrationForm.data
                                                            .administered_at
                                                    }
                                                    onChange={(e) =>
                                                        administrationForm.setData(
                                                            'administered_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>

                                            <div>
                                                <Label>
                                                    Scheduled for (optional)
                                                </Label>
                                                <Input
                                                    type="datetime-local"
                                                    value={
                                                        administrationForm.data
                                                            .scheduled_for
                                                    }
                                                    onChange={(e) =>
                                                        administrationForm.setData(
                                                            'scheduled_for',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>

                                            <div className="md:col-span-2">
                                                <Label>Notes</Label>
                                                <Textarea
                                                    value={
                                                        administrationForm.data
                                                            .notes
                                                    }
                                                    onChange={(e) =>
                                                        administrationForm.setData(
                                                            'notes',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="mt-3">
                                            <Button
                                                className="bg-status-success hover:bg-status-success"
                                                onClick={() => {
                                                    administrationForm.clearErrors();
                                                    if (
                                                        !administrationForm.data
                                                            .medication_id
                                                    )
                                                        return;
                                                    if (
                                                        selectedMedication?.is_prn &&
                                                        administrationForm.data
                                                            .status ===
                                                            'given' &&
                                                        !administrationForm.data
                                                            .reason
                                                    ) {
                                                        administrationForm.setError(
                                                            'reason',
                                                            'An indication is required for PRN medication.',
                                                        );
                                                        return;
                                                    }
                                                    if (
                                                        administrationForm.data
                                                            .status !==
                                                            'given' &&
                                                        !administrationForm.data
                                                            .reason_code
                                                    ) {
                                                        administrationForm.setError(
                                                            'reason_code',
                                                            'Select a reason for not giving the medication.',
                                                        );
                                                        return;
                                                    }
                                                    if (
                                                        administrationForm.data
                                                            .reason_code ===
                                                            'other' &&
                                                        !administrationForm.data
                                                            .reason
                                                    ) {
                                                        administrationForm.setError(
                                                            'reason',
                                                            'Describe the reason when choosing "Other".',
                                                        );
                                                        return;
                                                    }
                                                    if (
                                                        selectedMedication?.controlled_drug &&
                                                        administrationForm.data
                                                            .status ===
                                                            'given' &&
                                                        !administrationForm.data
                                                            .witnessed_by
                                                    ) {
                                                        administrationForm.setError(
                                                            'witnessed_by',
                                                            'A witness is required for controlled drug administration.',
                                                        );
                                                        return;
                                                    }
                                                    if (
                                                        selectedMedication?.controlled_drug &&
                                                        administrationForm.data
                                                            .status ===
                                                            'given' &&
                                                        administrationForm.data
                                                            .witnessed_by &&
                                                        !administrationForm.data
                                                            .witness_credential
                                                    ) {
                                                        administrationForm.setError(
                                                            'witness_credential',
                                                            'The witness must enter their password or PIN.',
                                                        );
                                                        return;
                                                    }
                                                    setConfirmAdminOpen(true);
                                                }}
                                                disabled={
                                                    administrationForm.processing ||
                                                    !administrationForm.data
                                                        .medication_id
                                                }
                                            >
                                                Save
                                            </Button>

                                            <Dialog
                                                open={confirmAdminOpen}
                                                onOpenChange={
                                                    setConfirmAdminOpen
                                                }
                                            >
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Confirm medication
                                                            administration
                                                        </DialogTitle>
                                                    </DialogHeader>

                                                    <div className="space-y-2 text-sm">
                                                        <div>
                                                            <span className="font-medium">
                                                                Medication:
                                                            </span>{' '}
                                                            {selectedMedication?.name ||
                                                                'Medication'}
                                                        </div>
                                                        <div>
                                                            <span className="font-medium">
                                                                Outcome:
                                                            </span>{' '}
                                                            {
                                                                administrationForm
                                                                    .data.status
                                                            }
                                                        </div>
                                                        {administrationForm.data
                                                            .reason && (
                                                            <div>
                                                                <span className="font-medium">
                                                                    Reason:
                                                                </span>{' '}
                                                                {
                                                                    administrationForm
                                                                        .data
                                                                        .reason
                                                                }
                                                            </div>
                                                        )}
                                                        <div>
                                                            <span className="font-medium">
                                                                Administered at:
                                                            </span>{' '}
                                                            {
                                                                administrationForm
                                                                    .data
                                                                    .administered_at
                                                            }
                                                        </div>
                                                    </div>

                                                    <DialogFooter>
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setConfirmAdminOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            className="bg-primary hover:bg-primary"
                                                            onClick={
                                                                submitAdministration
                                                            }
                                                            disabled={
                                                                administrationForm.processing
                                                            }
                                                        >
                                                            Confirm
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        </div>
                                    </div>
                                )}

                            <div className="space-y-2">
                                {administrations.map((a) => (
                                    <div
                                        key={a.id}
                                        className={cn(
                                            'rounded-lg border p-3',
                                            a.status === 'given'
                                                ? 'border-l-4 border-l-emerald-400'
                                                : 'border-l-4 border-l-amber-400',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="text-sm font-medium">
                                                {a.medication?.name ||
                                                    'Medication'}
                                            </div>
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                                    a.status === 'given'
                                                        ? 'bg-status-success-bg text-status-success'
                                                        : a.status === 'refused'
                                                          ? 'bg-status-critical-bg text-status-critical'
                                                          : a.status ===
                                                              'missed'
                                                            ? 'bg-status-warning-bg text-status-warning'
                                                            : 'bg-muted text-foreground',
                                                )}
                                            >
                                                {a.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {a.administered_at
                                                ? `Administered: ${new Date(a.administered_at).toLocaleString()}`
                                                : ''}
                                            {a.administeredBy?.name
                                                ? ` \u2022 By: ${a.administeredBy.name}`
                                                : ''}
                                            {a.dose_given
                                                ? ` \u2022 Dose: ${a.dose_given}`
                                                : ''}
                                            {a.late_minutes &&
                                            a.late_minutes > 0
                                                ? ` \u2022 Late: ${a.late_minutes} min`
                                                : ''}
                                            {a.serviceContext?.name
                                                ? ` \u2022 Context: ${a.serviceContext.name}`
                                                : ''}
                                        </div>
                                        {a.reason && a.status !== 'given' && (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Reason: {a.reason}
                                            </div>
                                        )}
                                        {a.notes && (
                                            <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                {a.notes}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {!administrations.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No administrations recorded yet.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Stock tab */}
                <div
                    className={cn(
                        'space-y-4',
                        focusSection !== 'stock' && 'hidden',
                    )}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2.5 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Package className="h-4 w-4" />
                                </div>
                                Stock
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {can_stock &&
                                medications.length > 0 &&
                                !showStockForm && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5"
                                        onClick={() => setShowStockForm(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Update Stock
                                    </Button>
                                )}
                            {can_stock &&
                                medications.length > 0 &&
                                showStockForm && (
                                    <div className="bg-primary/10 rounded-xl border border-dashed border-primary p-4">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2 text-sm font-medium text-primary">
                                                <Plus className="h-4 w-4" />
                                                Update stock
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setShowStockForm(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                            <div className="md:col-span-2">
                                                <Label>Medication</Label>
                                                <Select
                                                    value={`${stockForm.data.medication_id}`}
                                                    onValueChange={(v) =>
                                                        stockForm.setData(
                                                            'medication_id',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select medication" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {medications.map(
                                                            (m) => (
                                                                <SelectItem
                                                                    key={m.id}
                                                                    value={`${m.id}`}
                                                                >
                                                                    {m.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {selectedStockMedication?.controlled_drug && (
                                                <>
                                                    <div className="md:col-span-2">
                                                        <Label>
                                                            Reason (required for
                                                            controlled drug
                                                            stock)
                                                        </Label>
                                                        <Input
                                                            value={
                                                                stockForm.data
                                                                    .reason
                                                            }
                                                            onChange={(e) =>
                                                                stockForm.setData(
                                                                    'reason',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="e.g. stock count, discrepancy investigation"
                                                        />
                                                        {stockForm.errors
                                                            .reason && (
                                                            <div className="mt-1 text-xs text-status-critical">
                                                                {
                                                                    stockForm
                                                                        .errors
                                                                        .reason
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="md:col-span-2">
                                                        <Label>
                                                            Witness (required)
                                                        </Label>
                                                        <Select
                                                            value={`${stockForm.data.witnessed_by}`}
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                stockForm.setData(
                                                                    'witnessed_by',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select witness" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {witnesses.map(
                                                                    (
                                                                        w: any,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                w.id
                                                                            }
                                                                            value={`${w.id}`}
                                                                        >
                                                                            {
                                                                                w.name
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        {stockForm.errors
                                                            .witnessed_by && (
                                                            <div className="mt-1 text-xs text-status-critical">
                                                                {
                                                                    stockForm
                                                                        .errors
                                                                        .witnessed_by
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </>
                                            )}
                                            <div>
                                                <Label>On hand</Label>
                                                <Input
                                                    value={
                                                        stockForm.data.on_hand
                                                    }
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'on_hand',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>Unit</Label>
                                                <Input
                                                    value={stockForm.data.unit}
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'unit',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>Reorder level</Label>
                                                <Input
                                                    value={
                                                        stockForm.data
                                                            .reorder_level
                                                    }
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'reorder_level',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>
                                                    Last counted (optional)
                                                </Label>
                                                <Input
                                                    type="date"
                                                    value={
                                                        stockForm.data
                                                            .last_counted_at
                                                    }
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'last_counted_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="md:col-span-2">
                                                <Label>Notes</Label>
                                                <Textarea
                                                    value={stockForm.data.notes}
                                                    onChange={(e) =>
                                                        stockForm.setData(
                                                            'notes',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="mt-3">
                                            <Button
                                                className="bg-primary hover:bg-primary"
                                                onClick={() => {
                                                    stockForm.clearErrors();
                                                    if (
                                                        !stockForm.data
                                                            .medication_id
                                                    )
                                                        return;
                                                    if (
                                                        selectedStockMedication?.controlled_drug
                                                    ) {
                                                        if (
                                                            !stockForm.data
                                                                .reason
                                                        ) {
                                                            stockForm.setError(
                                                                'reason',
                                                                'Reason is required for controlled drug stock updates.',
                                                            );
                                                            return;
                                                        }
                                                        if (
                                                            !stockForm.data
                                                                .witnessed_by
                                                        ) {
                                                            stockForm.setError(
                                                                'witnessed_by',
                                                                'Witness is required for controlled drug stock updates.',
                                                            );
                                                            return;
                                                        }
                                                    }
                                                    stockForm.put(
                                                        `/operations/clients/${client.id}/medical/medications/${stockForm.data.medication_id}/stock`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                                disabled={
                                                    stockForm.processing ||
                                                    !stockForm.data
                                                        .medication_id
                                                }
                                            >
                                                Save
                                            </Button>
                                        </div>
                                    </div>
                                )}

                            <div className="space-y-2">
                                {medications.map((m) => (
                                    <div
                                        key={m.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="flex items-center gap-2">
                                                <Package className="h-4 w-4 text-primary" />
                                                <span className="text-sm font-medium">
                                                    {m.name}
                                                </span>
                                            </div>
                                            <div className="text-xs font-medium text-foreground">
                                                {m.stock?.on_hand !== null &&
                                                m.stock?.on_hand !== undefined
                                                    ? `${m.stock.on_hand}${m.stock.unit ? ` ${m.stock.unit}` : ''}`
                                                    : '\u2014'}
                                            </div>
                                        </div>
                                        {m.stock?.reorder_level !== null &&
                                            m.stock?.reorder_level !==
                                                undefined && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Reorder at:{' '}
                                                    {m.stock.reorder_level}
                                                </div>
                                            )}
                                        {m.stock?.notes && (
                                            <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                {m.stock.notes}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {!medications.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No medications listed.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Controlled Drugs tab */}
                {can_controlled_view && focusSection === 'controlled_drugs' && (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2.5 text-base">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
                                        <Shield className="h-4 w-4" />
                                    </div>
                                    Controlled Drug Register
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {controlled_entries.map((e: any) => (
                                    <div
                                        key={e.id}
                                        className="rounded-lg border border-l-4 border-l-rose-300 p-3"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="text-sm font-medium">
                                                {e.medication?.name ||
                                                    'Medication'}
                                            </div>
                                            <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical">
                                                {e.entry_type}
                                                {e.recorded_at
                                                    ? ` \u2022 ${new Date(e.recorded_at).toLocaleString()}`
                                                    : ''}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {e.recordedBy?.name
                                                ? `By: ${e.recordedBy.name}`
                                                : ''}
                                            {e.witnessedBy?.name
                                                ? ` \u2022 Witness: ${e.witnessedBy.name}`
                                                : ''}
                                            {e.serviceContext?.name
                                                ? ` \u2022 Context: ${e.serviceContext.name}`
                                                : ''}
                                        </div>
                                        {(e.on_hand_before !== null ||
                                            e.on_hand_after !== null) && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Stock:{' '}
                                                {e.on_hand_before ?? '\u2014'}{' '}
                                                \u2192{' '}
                                                {e.on_hand_after ?? '\u2014'}
                                                {e.unit ? ` ${e.unit}` : ''}
                                            </div>
                                        )}
                                        {e.reason && (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Reason: {e.reason}
                                            </div>
                                        )}
                                        {e.notes && (
                                            <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                                {e.notes}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {!controlled_entries.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No controlled drug entries recorded yet.
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Controlled Drug Discrepancies */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2.5 text-base">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                                        <AlertTriangle className="h-4 w-4" />
                                    </div>
                                    Controlled Drug Discrepancies
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {controlled_discrepancies.map((d: any) => (
                                    <div
                                        key={d.id}
                                        className={cn(
                                            'rounded-lg border p-3',
                                            d.status === 'open'
                                                ? 'border-l-4 border-l-amber-400 bg-status-warning-bg'
                                                : 'border-l-4 border-l-slate-200',
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="text-sm font-medium">
                                                {d.medication?.name ||
                                                    'Medication'}
                                            </div>
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                                    d.status === 'open'
                                                        ? 'bg-status-warning-bg text-status-warning'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {d.status}
                                                {d.reported_at
                                                    ? ` \u2022 ${new Date(d.reported_at).toLocaleString()}`
                                                    : ''}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {d.reportedBy?.name
                                                ? `Reported by: ${d.reportedBy.name}`
                                                : ''}
                                            {d.witnessedBy?.name
                                                ? ` \u2022 Witness: ${d.witnessedBy.name}`
                                                : ''}
                                            {d.serviceContext?.name
                                                ? ` \u2022 Context: ${d.serviceContext.name}`
                                                : ''}
                                        </div>
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            Stock:{' '}
                                            {d.on_hand_before ?? '\u2014'}{' '}
                                            \u2192 {d.on_hand_after ?? '\u2014'}
                                            {d.difference !== null &&
                                            d.difference !== undefined
                                                ? ` \u2022 Difference: ${d.difference}`
                                                : ''}
                                        </div>
                                        {d.reason && (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Reason: {d.reason}
                                            </div>
                                        )}
                                        {d.status === 'closed' &&
                                            (d.resolution_notes ||
                                                d.resolvedBy?.name) && (
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    Closed
                                                    {d.resolvedBy?.name
                                                        ? ` by ${d.resolvedBy.name}`
                                                        : ''}
                                                    {d.resolution_notes
                                                        ? ` \u2022 ${d.resolution_notes}`
                                                        : ''}
                                                </div>
                                            )}

                                        {d.status === 'open' &&
                                            can_controlled_record && (
                                                <div className="mt-3 flex justify-end">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            setSelectedDiscId(
                                                                d.id,
                                                            );
                                                            closeDiscForm.reset(
                                                                'resolution_notes',
                                                            );
                                                            setCloseDiscOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        Close discrepancy
                                                    </Button>
                                                </div>
                                            )}
                                    </div>
                                ))}

                                {!controlled_discrepancies.length && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        No controlled drug discrepancies.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </PageLayout>

            <Dialog open={closeDiscOpen} onOpenChange={setCloseDiscOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close discrepancy</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>Resolution notes (optional)</Label>
                        <Textarea
                            value={closeDiscForm.data.resolution_notes}
                            onChange={(e) =>
                                closeDiscForm.setData(
                                    'resolution_notes',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCloseDiscOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-primary hover:bg-primary"
                            onClick={() => {
                                if (!selectedDiscId) return;
                                closeDiscForm.post(
                                    `/operations/clients/${client.id}/medical/controlled-discrepancies/${selectedDiscId}/close`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setCloseDiscOpen(false);
                                            setSelectedDiscId(null);
                                        },
                                    },
                                );
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
