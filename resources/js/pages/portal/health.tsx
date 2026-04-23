import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Activity, AlertTriangle, Heart, Lock, Pill, ShieldCheck, Utensils, Waypoints } from 'lucide-react';

type MedicalProfile = {
    medical_history?: string;
    disabilities?: string;
    allergies?: string;
    notes?: string;
};

type Medication = {
    id: number;
    name: string;
    dosage?: string;
    frequency?: string;
    route?: string;
    instructions?: string;
};

type Condition = {
    id: number;
    label: string;
    severity?: string;
    notes?: string;
};

type CarePlan = {
    goals?: string;
    important_to_me?: string;
};

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        avatar?: string | null;
        profile_photo_url?: string | null;
        dietary_requirements?: string | null;
        mobility_needs?: string | null;
    };
    medicalProfile?: MedicalProfile | null;
    medications: Medication[];
    conditions: Condition[];
    carePlan?: CarePlan | null;
    permissions: {
        can_view_medical: boolean;
        can_view_medications: boolean;
        show_care_plans: boolean;
        show_medication_status: boolean;
    };
};

const severityColors: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800',
    mild: 'bg-blue-100 text-blue-800',
    moderate: 'bg-yellow-100 text-yellow-800',
    high: 'bg-orange-100 text-orange-800',
    severe: 'bg-red-100 text-red-800',
    critical: 'bg-red-100 text-red-800',
};

export default function Health({
    client,
    medicalProfile,
    medications,
    conditions,
    carePlan,
    permissions,
}: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();

    if (!permissions.can_view_medical) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Portal', href: '/portal' },
                    { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
                    { title: 'Health', href: `/portal/clients/${client.id}/health` },
                ]}
            >
                <Head title={`${clientName} - Health`} />
                <div className="mx-auto max-w-7xl p-4 md:p-6">
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                            <Lock className="mb-3 h-10 w-10 text-muted-foreground/40" />
                            <p className="text-sm font-medium text-muted-foreground">
                                Medical information is not available for your access level
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground/70">
                                Please contact the care team if you need access to health records.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
                { title: 'Health', href: `/portal/clients/${client.id}/health` },
            ]}
        >
            <Head title={`${clientName} - Health`} />

            <div className="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
                {/* Two-column layout */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Left Column */}
                    <div className="space-y-6">
                        {/* Medical Summary */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Activity className="h-4 w-4 text-primary" />
                                    Medical Summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {medicalProfile?.allergies && (
                                    <div>
                                        <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                            Allergies
                                        </p>
                                        <div className="rounded-md bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-300">
                                            <div className="flex items-start gap-2">
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>{medicalProfile.allergies}</span>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {medicalProfile?.disabilities && (
                                    <div>
                                        <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                            Disabilities
                                        </p>
                                        <p className="text-sm">{medicalProfile.disabilities}</p>
                                    </div>
                                )}

                                {medicalProfile?.medical_history && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                Medical History
                                            </p>
                                            <p className="text-sm whitespace-pre-line">{medicalProfile.medical_history}</p>
                                        </div>
                                    </>
                                )}

                                {medicalProfile?.notes && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                Notes
                                            </p>
                                            <p className="text-sm whitespace-pre-line">{medicalProfile.notes}</p>
                                        </div>
                                    </>
                                )}

                                {!medicalProfile?.allergies &&
                                    !medicalProfile?.disabilities &&
                                    !medicalProfile?.medical_history &&
                                    !medicalProfile?.notes && (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            No medical summary on file
                                        </p>
                                    )}
                            </CardContent>
                        </Card>

                        {/* Conditions */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Heart className="h-4 w-4 text-primary" />
                                    Conditions
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {conditions.length > 0 ? (
                                    <div className="space-y-3">
                                        {conditions.map((condition) => (
                                            <div
                                                key={condition.id}
                                                className="flex items-start justify-between gap-3 rounded-lg border p-3"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium">{condition.label}</p>
                                                    {condition.notes && (
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {condition.notes}
                                                        </p>
                                                    )}
                                                </div>
                                                {condition.severity && (
                                                    <Badge
                                                        className={`${severityColors[condition.severity.toLowerCase()] ?? 'bg-muted text-foreground'} shrink-0 border-0 capitalize`}
                                                    >
                                                        {condition.severity}
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
                    </div>

                    {/* Right Column */}
                    <div className="space-y-6">
                        {/* Medications */}
                        {permissions.can_view_medications && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Pill className="h-4 w-4 text-primary" />
                                        Medications
                                        {permissions.show_medication_status && medications.length > 0 && (
                                            <Badge variant="secondary" className="ml-auto text-xs">
                                                {medications.length} active
                                            </Badge>
                                        )}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {medications.length > 0 ? (
                                        <div className="space-y-3">
                                            {medications.map((med) => (
                                                <div key={med.id} className="rounded-lg border p-3">
                                                    <p className="text-sm font-semibold">{med.name}</p>
                                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                        {med.dosage && <span>{med.dosage}</span>}
                                                        {med.frequency && <span>{med.frequency}</span>}
                                                        {med.route && <span>{med.route}</span>}
                                                    </div>
                                                    {med.instructions && (
                                                        <p className="mt-1.5 text-xs text-muted-foreground italic">
                                                            {med.instructions}
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            No medications recorded
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Care Plan */}
                        {permissions.show_care_plans && carePlan && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <ShieldCheck className="h-4 w-4 text-primary" />
                                        Care Plan
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {carePlan.goals && (
                                        <div>
                                            <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                Goals
                                            </p>
                                            <p className="text-sm whitespace-pre-line">{carePlan.goals}</p>
                                        </div>
                                    )}
                                    {carePlan.goals && carePlan.important_to_me && <Separator />}
                                    {carePlan.important_to_me && (
                                        <div>
                                            <p className="mb-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                Important to Me
                                            </p>
                                            <p className="text-sm whitespace-pre-line">{carePlan.important_to_me}</p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* Bottom full-width row */}
                {(client.dietary_requirements || client.mobility_needs) && (
                    <div className="grid gap-6 md:grid-cols-2">
                        {client.dietary_requirements && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Utensils className="h-4 w-4 text-primary" />
                                        Dietary Requirements
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm">{client.dietary_requirements}</p>
                                </CardContent>
                            </Card>
                        )}
                        {client.mobility_needs && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Waypoints className="h-4 w-4 text-primary" />
                                        Mobility Needs
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm">{client.mobility_needs}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
