import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import {
    CheckCircle,
    Loader2,
    Phone,
    Pill,
    Shield,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

type Transport = {
    id: number;
    resident_name: string;
    transport_type: string;
    status: string;
    asset: { id: number; name: string; asset_tag?: string } | null;
};

type CareNeed = {
    id: number;
    label: string;
    notes: string | null;
};

type EmergencyContact = {
    name: string;
    relation: string;
    phone: string;
};

type Medication = {
    name: string;
    dosage: string | null;
    frequency: string | null;
};

type Props = {
    transport: Transport;
    care_needs: CareNeed[];
    emergency_contacts: EmergencyContact[];
    medications: Medication[];
    pre_check_completed: boolean;
};

type ChecklistItem = {
    key: string;
    label: string;
    description: string;
    icon: typeof CheckCircle;
    required: boolean;
};

const CHECKLIST_ITEMS: ChecklistItem[] = [
    {
        key: 'care_needs_reviewed',
        label: 'Care Needs Reviewed',
        description: 'Confirm care needs and support requirements have been reviewed for this transport.',
        icon: User,
        required: true,
    },
    {
        key: 'emergency_contacts_accessible',
        label: 'Emergency Contacts Accessible',
        description: 'Emergency contact numbers are saved and accessible during the journey.',
        icon: Phone,
        required: true,
    },
    {
        key: 'medication_packed',
        label: 'Medication Packed',
        description: 'All required medications are packed for the journey duration.',
        icon: Pill,
        required: false, // conditional on medications existing
    },
    {
        key: 'vehicle_accessibility_confirmed',
        label: 'Vehicle Accessibility Confirmed',
        description: 'Vehicle is suitable for resident mobility needs (ramp, harness, etc.).',
        icon: Shield,
        required: true,
    },
    {
        key: 'seatbelt_harness_check',
        label: 'Seatbelt / Harness Check',
        description: 'Seatbelt or harness is properly fitted and secure before departure.',
        icon: Shield,
        required: true,
    },
];

export default function TransportPreCheck({
    transport,
    care_needs,
    emergency_contacts,
    medications,
    pre_check_completed,
}: Props) {
    const t = transport ?? ({} as Transport);
    const hasMedications = (medications ?? []).length > 0;

    // Track checked states for all items
    const [checks, setChecks] = useState<Record<string, boolean | null>>({
        care_needs_reviewed: null,
        emergency_contacts_accessible: null,
        medication_packed: null,
        vehicle_accessibility_confirmed: null,
        seatbelt_harness_check: null,
    });

    const [submitting, setSubmitting] = useState(false);

    const toggleCheck = (key: string, value: boolean) => {
        setChecks((prev) => ({ ...prev, [key]: value }));
    };

    // Determine if all required checks are passed
    const relevantItems = CHECKLIST_ITEMS.filter(
        (item) => item.required || (item.key === 'medication_packed' && hasMedications),
    );
    const allPassed = relevantItems.every((item) => checks[item.key] === true);

    const handleSubmit = () => {
        setSubmitting(true);
        router.post(
            `/fleet-assets/transports/${t.id}/pre-check`,
            { checks },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Transport Logs', href: '/fleet-assets/transports' },
                { title: `Transport #${t.id ?? ''}`, href: `/fleet-assets/transports/${t.id}` },
                { title: 'Pre-Transport Check', href: '#' },
            ]}
        >
            <Head title={`Pre-Check - Transport #${t.id ?? ''}`} />
            <PageShell>
                <FleetHero
                    title="Pre-Transport Safety Check"
                    subtitle={`Transport #${t.id ?? ''} - ${t.resident_name ?? '---'}`}
                    backHref={`/fleet-assets/transports/${t.id}`}
                    backLabel="Back to Transport"
                />

                {pre_check_completed && (
                    <div className="rounded-lg border-2 border-status-success/30 bg-status-success-bg p-4 dark:border-status-success/30">
                        <div className="flex items-center gap-3">
                            <CheckCircle className="h-6 w-6 text-status-success" />
                            <div>
                                <p className="font-semibold text-status-success dark:text-status-success">Pre-Check Completed</p>
                                <p className="text-sm text-status-success dark:text-status-success">
                                    All safety checks have been completed for this transport.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    {/* Left: Checklist */}
                    <div className="space-y-4">
                        {/* Resident Info Card */}
                        <Card className="border bg-primary/10 dark:bg-primary/30">
                            <CardContent className="p-4">
                                <div className="flex items-center gap-4">
                                    <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/40">
                                        <User className="h-6 w-6 text-primary dark:text-primary" />
                                    </div>
                                    <div>
                                        <p className="text-lg font-semibold">{t.resident_name ?? '---'}</p>
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Badge variant="secondary" className="capitalize">{t.transport_type ?? ''}</Badge>
                                            {t.asset && (
                                                <span>Vehicle: {t.asset.name}</span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Checklist Items */}
                        <div className="space-y-3">
                            {CHECKLIST_ITEMS.map((item) => {
                                // Skip medication check if no medications
                                if (item.key === 'medication_packed' && !hasMedications) return null;

                                const checked = checks[item.key];
                                const Icon = item.icon;

                                return (
                                    <Card
                                        key={item.key}
                                        className={cn(
                                            'border-2 transition-colors',
                                            checked === true && 'border-status-success/30 bg-status-success-bg dark:border-status-success/30',
                                            checked === false && 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30',
                                            checked === null && 'border-border',
                                        )}
                                    >
                                        <CardContent className="p-4">
                                            <div className="flex items-center justify-between gap-4">
                                                <div className="flex items-center gap-3">
                                                    <div className={cn(
                                                        'flex h-10 w-10 items-center justify-center rounded-full',
                                                        checked === true && 'bg-status-success-bg',
                                                        checked === false && 'bg-status-critical-bg',
                                                        checked === null && 'bg-muted',
                                                    )}>
                                                        <Icon className={cn(
                                                            'h-5 w-5',
                                                            checked === true && 'text-status-success',
                                                            checked === false && 'text-status-critical',
                                                            checked === null && 'text-muted-foreground',
                                                        )} />
                                                    </div>
                                                    <div>
                                                        <p className="font-medium">{item.label}</p>
                                                        <p className="text-sm text-muted-foreground">{item.description}</p>
                                                    </div>
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant={checked === true ? 'default' : 'outline'}
                                                        size="lg"
                                                        onClick={() => toggleCheck(item.key, true)}
                                                        className={cn(
                                                            'h-14 w-14 p-0',
                                                            checked === true && 'bg-status-success hover:bg-status-success',
                                                        )}
                                                    >
                                                        <CheckCircle className="h-6 w-6" />
                                                    </Button>
                                                    <Button
                                                        variant={checked === false ? 'destructive' : 'outline'}
                                                        size="lg"
                                                        onClick={() => toggleCheck(item.key, false)}
                                                        className="h-14 w-14 p-0"
                                                    >
                                                        <XCircle className="h-6 w-6" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* Submit */}
                        {!pre_check_completed && (
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={!allPassed || submitting}
                                onClick={handleSubmit}
                            >
                                {submitting ? (
                                    <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                                ) : (
                                    <CheckCircle className="mr-2 h-5 w-5" />
                                )}
                                Confirm Pre-Transport Check
                            </Button>
                        )}
                    </div>

                    {/* Right: Reference Info */}
                    <div className="space-y-4">
                        {/* Care Needs */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <User className="h-4 w-4" />
                                    Care Needs
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {(care_needs ?? []).length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No specific care needs recorded.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {(care_needs ?? []).map((need) => (
                                            <div key={need.id} className="rounded-md bg-muted/40 p-3">
                                                <p className="text-sm font-medium">{need.label}</p>
                                                {need.notes && (
                                                    <p className="mt-1 text-xs text-muted-foreground">{need.notes}</p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Emergency Contacts */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Phone className="h-4 w-4" />
                                    Emergency Contacts
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {(emergency_contacts ?? []).length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No emergency contacts on file.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {(emergency_contacts ?? []).map((contact, i) => (
                                            <div key={i} className="rounded-md bg-muted/40 p-3">
                                                <p className="text-sm font-medium">{contact.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {contact.relation} &middot; {contact.phone}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Medications */}
                        {hasMedications && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Pill className="h-4 w-4" />
                                        Medications
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {(medications ?? []).map((med, i) => (
                                            <div key={i} className="rounded-md bg-muted/40 p-3">
                                                <p className="text-sm font-medium">{med.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {[med.dosage, med.frequency].filter(Boolean).join(' - ') || 'No details'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
