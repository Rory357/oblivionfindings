import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Calendar,
    Car,
    Clock,
    MapPin,
    Save,
    Shield,
    User,
} from 'lucide-react';
import { formatDateTime } from '@/lib/fleet-utils';


type DamageDetails = {
    areas?: string[];
    estimated_cost?: number | null;
};

type Props = {
    incident: {
        id: number;
        asset: { id: number; name: string; registration_number?: string | null } | null;
        reported_by: { id: number; name: string } | null;
        driver: { id: number; name: string } | null;
        booking: { id: number; purpose: string } | null;
        incident_type: string;
        severity: string;
        occurred_at: string | null;
        location: string | null;
        description: string;
        damage_details: DamageDetails | null;
        police_notified: boolean;
        police_reference: string | null;
        insurance_claimed: boolean;
        insurance_reference: string | null;
        status: string;
        resolution_notes: string | null;
        resolved_at: string | null;
        created_at: string | null;
    };
};

const severityBannerColors: Record<string, string> = {
    minor: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-200',
    moderate: 'bg-orange-50 border-orange-200 text-orange-900 dark:bg-orange-950/30 dark:border-orange-800 dark:text-orange-200',
    major: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200',
    critical: 'bg-red-100 border-red-300 text-red-950 dark:bg-red-950/50 dark:border-red-700 dark:text-red-100',
};

const severityIconColors: Record<string, string> = {
    minor: 'text-amber-600',
    moderate: 'text-orange-600',
    major: 'text-red-600',
    critical: 'text-red-800 dark:text-red-400',
};

function statusBadge(status: string) {
    switch (status) {
        case 'reported': return <Badge variant="outline">Reported</Badge>;
        case 'investigating': return <Badge variant="default" className="bg-blue-600">Investigating</Badge>;
        case 'resolved': return <Badge variant="default" className="bg-green-600">Resolved</Badge>;
        case 'closed': return <Badge variant="secondary">Closed</Badge>;
        default: return <Badge variant="outline">{status}</Badge>;
    }
}

const typeLabels: Record<string, string> = {
    collision: 'Collision',
    damage: 'Damage',
    theft: 'Theft',
    vandalism: 'Vandalism',
    breakdown: 'Breakdown',
    near_miss: 'Near Miss',
    other: 'Other',
};

export default function IncidentShow({ incident: inc }: Props) {
    const updateForm = useForm({
        status: inc.status,
        resolution_notes: inc.resolution_notes ?? '',
    });

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        updateForm.put(`/fleet-assets/incidents/${inc.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Incidents', href: '/fleet-assets/incidents' },
                { title: `Incident #${inc.id}`, href: '#' },
            ]}
        >
            <Head title={`Incident #${inc.id}`} />
            <PageShell>
                <PageHeader
                    title={`Incident #${inc.id}`}
                    backHref="/fleet-assets/incidents"
                    backLabel="Back to Incidents"
                />

                {/* Severity Banner */}
                <div className={cn('rounded-lg border px-5 py-4', severityBannerColors[inc.severity] ?? severityBannerColors.minor)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <AlertTriangle className={cn('h-6 w-6', severityIconColors[inc.severity])} />
                            <div>
                                <span className="text-lg font-bold capitalize">{inc.severity} Severity</span>
                                <span className="mx-2 opacity-50">|</span>
                                <span className="font-medium">{typeLabels[inc.incident_type] ?? inc.incident_type}</span>
                            </div>
                        </div>
                        {statusBadge(inc.status)}
                    </div>
                </div>

                {/* 2-Column Layout */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left: Incident Details */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Incident Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-3 text-sm">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <Calendar className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Date & Time</dt>
                                                <dd className="font-medium">
                                                    {inc.occurred_at ? formatDateTime(inc.occurred_at) : '---'}
                                                </dd>
                                            </div>
                                        </div>
                                        {inc.location && (
                                            <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                                <MapPin className="h-4 w-4 text-muted-foreground" />
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Location</dt>
                                                    <dd className="font-medium">{inc.location}</dd>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <User className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Reported By</dt>
                                                <dd className="font-medium">{inc.reported_by?.name ?? '---'}</dd>
                                            </div>
                                        </div>
                                        {inc.driver && (
                                            <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                                <User className="h-4 w-4 text-muted-foreground" />
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Driver</dt>
                                                    <dd className="font-medium">{inc.driver.name}</dd>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                    {inc.booking && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Related Booking</dt>
                                            <dd className="mt-1">
                                                <Link href={`/fleet-assets/bookings/${inc.booking.id}`} className="text-sm text-primary hover:underline font-medium">
                                                    Booking #{inc.booking.id} - {inc.booking.purpose}
                                                </Link>
                                            </dd>
                                        </div>
                                    )}
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Reported At</dt>
                                        <dd className="mt-1 font-medium">
                                            {inc.created_at ? formatDateTime(inc.created_at) : '---'}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Description */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Description</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-sm leading-relaxed">{inc.description}</p>
                            </CardContent>
                        </Card>

                        {/* Damage Details */}
                        {inc.damage_details && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                                        Damage Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {(inc.damage_details.areas ?? []).length > 0 && (
                                        <div>
                                            <Label className="text-muted-foreground text-xs">Affected Areas</Label>
                                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                {(inc.damage_details.areas ?? []).map((area) => (
                                                    <Badge key={area} variant="outline" className="px-3 py-1">{area}</Badge>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    {inc.damage_details.estimated_cost != null && (
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <Label className="text-muted-foreground text-xs">Estimated Repair Cost</Label>
                                            <div className="mt-1 text-2xl font-bold">
                                                ${Number(inc.damage_details.estimated_cost).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Resolution */}
                        {inc.resolved_at && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Resolution</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="text-sm">
                                        <span className="text-muted-foreground">Resolved At:</span>{' '}
                                        <span className="font-medium">{formatDateTime(inc.resolved_at)}</span>
                                    </div>
                                    {inc.resolution_notes && (
                                        <p className="whitespace-pre-wrap text-sm">{inc.resolution_notes}</p>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right: Vehicle, Police, Insurance, Status Update */}
                    <div className="space-y-4">
                        {/* Vehicle Info */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Car className="h-4 w-4" />
                                    Vehicle
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {inc.asset ? (
                                    <Link href={`/fleet-assets/vehicles/${inc.asset.id}`} className="block rounded-lg border p-4 transition-all duration-200 hover:bg-muted/50 hover:border-primary/30 hover:shadow-lg hover:-translate-y-0.5">
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <Car className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div className="font-semibold">{inc.asset.name}</div>
                                                {inc.asset.registration_number && (
                                                    <div className="text-xs text-muted-foreground">Rego: {inc.asset.registration_number}</div>
                                                )}
                                            </div>
                                        </div>
                                    </Link>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No vehicle linked</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Police & Insurance */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Shield className="h-4 w-4" />
                                    Police & Insurance
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="rounded-md border p-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">Police Notified</span>
                                        <Badge variant={inc.police_notified ? 'default' : 'secondary'}>
                                            {inc.police_notified ? 'Yes' : 'No'}
                                        </Badge>
                                    </div>
                                    {inc.police_reference && (
                                        <div className="mt-2 text-sm">
                                            <span className="text-muted-foreground">Ref: </span>
                                            <span className="font-mono font-medium">{inc.police_reference}</span>
                                        </div>
                                    )}
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">Insurance Claimed</span>
                                        <Badge variant={inc.insurance_claimed ? 'default' : 'secondary'}>
                                            {inc.insurance_claimed ? 'Yes' : 'No'}
                                        </Badge>
                                    </div>
                                    {inc.insurance_reference && (
                                        <div className="mt-2 text-sm">
                                            <span className="text-muted-foreground">Ref: </span>
                                            <span className="font-mono font-medium">{inc.insurance_reference}</span>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Status Update Form */}
                        {!['closed'].includes(inc.status) && (
                            <Card className="border-2 border-dashed">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Update Status</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={handleUpdate} className="space-y-3">
                                        <div>
                                            <Label className="text-xs">Status</Label>
                                            <Select
                                                value={updateForm.data.status}
                                                onValueChange={(v) => updateForm.setData('status', v)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="reported">Reported</SelectItem>
                                                    <SelectItem value="investigating">Investigating</SelectItem>
                                                    <SelectItem value="resolved">Resolved</SelectItem>
                                                    <SelectItem value="closed">Closed</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label className="text-xs">Resolution Notes</Label>
                                            <textarea
                                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                rows={3}
                                                value={updateForm.data.resolution_notes}
                                                onChange={(e) => updateForm.setData('resolution_notes', e.target.value)}
                                                placeholder="Add resolution notes..."
                                            />
                                        </div>
                                        <Button type="submit" disabled={updateForm.processing} className="w-full">
                                            <Save className="mr-2 h-4 w-4" />
                                            Update Incident
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
