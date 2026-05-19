import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Car,
    Check,
    CheckCircle,
    Clock,
    Fuel,
    Shield,
    User,
    XCircle,
} from 'lucide-react';
import { formatDateTime, formatDistance } from '@/lib/fleet-utils';


type DamageNote = {
    area: string;
    description: string;
};

type Props = {
    handover: {
        id: number;
        asset: { id: number; name: string; registration_number?: string | null } | null;
        outgoing_user: { id: number; name: string } | null;
        incoming_user: { id: number; name: string } | null;
        odometer_km: number | null;
        fuel_level: string | null;
        exterior_condition: string;
        interior_condition: string;
        keys_present: boolean;
        documents_present: boolean;
        first_aid_kit: boolean;
        fire_extinguisher: boolean;
        damage_notes: DamageNote[] | null;
        notes: string | null;
        status: string;
        handed_over_at: string | null;
        accepted_at: string | null;
        created_at: string | null;
    };
    current_user_id: number;
};

const statusBannerColors: Record<string, string> = {
    pending_acceptance: 'bg-status-warning-bg border-status-warning/30 text-status-warning dark:bg-status-warning-bg dark:border-status-warning/30 dark:text-status-warning',
    accepted: 'bg-primary/10 border-primary text-primary dark:bg-primary/30 dark:border-primary/30 dark:text-primary/70',
    disputed: 'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
};

function statusBadge(status: string) {
    switch (status) {
        case 'accepted':
            return <Badge variant="default"><CheckCircle className="mr-1 h-3 w-3" />Accepted</Badge>;
        case 'disputed':
            return <Badge variant="destructive"><XCircle className="mr-1 h-3 w-3" />Disputed</Badge>;
        case 'pending_acceptance':
            return <Badge variant="outline"><Clock className="mr-1 h-3 w-3" />Pending Acceptance</Badge>;
        default:
            return <Badge variant="outline">{status.replace(/_/g, ' ')}</Badge>;
    }
}

function conditionLabel(condition: string) {
    const labels: Record<string, { text: string; color: string }> = {
        good: { text: 'Good', color: 'text-status-success' },
        clean: { text: 'Clean', color: 'text-status-success' },
        minor_damage: { text: 'Minor Damage', color: 'text-status-warning' },
        acceptable: { text: 'Acceptable', color: 'text-status-warning' },
        significant_damage: { text: 'Significant Damage', color: 'text-status-critical' },
        needs_cleaning: { text: 'Needs Cleaning', color: 'text-status-critical' },
    };
    const info = labels[condition] ?? { text: condition.replace(/_/g, ' '), color: '' };
    return <span className={cn('font-medium', info.color)}>{info.text}</span>;
}

function fuelLabel(level: string | null) {
    if (!level) return '---';
    const labels: Record<string, string> = { full: 'Full', '3/4': '3/4', '1/2': '1/2', '1/4': '1/4', empty: 'Empty' };
    return labels[level] ?? level;
}

const fuelPercentage: Record<string, number> = { full: 100, '3/4': 75, '1/2': 50, '1/4': 25, empty: 0 };

export default function HandoverShow({ handover: h, current_user_id }: Props) {
    const disputeForm = useForm({ dispute_reason: '' });

    const canAcceptOrDispute =
        h.status === 'pending_acceptance' &&
        h.incoming_user?.id === current_user_id;

    const handleAccept = () => {
        router.post(`/fleet-assets/handovers/${h.id}/accept`);
    };

    const handleDispute = (e: React.FormEvent) => {
        e.preventDefault();
        disputeForm.post(`/fleet-assets/handovers/${h.id}/dispute`);
    };

    const fuelPct = fuelPercentage[h.fuel_level ?? ''] ?? 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Shift Handovers', href: '/fleet-assets/handovers' },
                { title: `Handover #${h.id}`, href: '#' },
            ]}
        >
            <Head title={`Handover #${h.id}`} />
            <PageShell>
                <PageHero
                    title={`Shift Handover #${h.id}`}
                    backHref="/fleet-assets/handovers"
                    backLabel="Back to Handovers"
                />

                {/* Status Banner */}
                <div className={cn('rounded-lg border px-5 py-4', statusBannerColors[h.status] ?? statusBannerColors.pending_acceptance)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <ArrowLeftRight className="h-5 w-5" />
                            <span className="font-medium">Shift Handover #{h.id}</span>
                        </div>
                        {statusBadge(h.status)}
                    </div>
                    <div className="mt-3 flex items-center gap-6 text-sm">
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 opacity-60" />
                            <span className="opacity-70">From:</span>
                            <span className="font-medium">{h.outgoing_user?.name ?? '---'}</span>
                        </div>
                        <ArrowLeftRight className="h-3 w-3 opacity-40" />
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 opacity-60" />
                            <span className="opacity-70">To:</span>
                            <span className="font-medium">{h.incoming_user?.name ?? '---'}</span>
                        </div>
                    </div>
                </div>

                {/* 2-Column: Vehicle Condition (left), Checklist (right) */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
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
                                {h.asset ? (
                                    <Link href={`/fleet-assets/vehicles/${h.asset.id}`} className="block rounded-lg border p-4 transition-all duration-200 hover:bg-muted/50 hover:border-primary/30 hover:shadow-lg hover:-translate-y-0.5">
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <Car className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div className="font-semibold">{h.asset.name}</div>
                                                {h.asset.registration_number && (
                                                    <div className="text-xs text-muted-foreground">Rego: {h.asset.registration_number}</div>
                                                )}
                                            </div>
                                        </div>
                                    </Link>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No vehicle</p>
                                )}

                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-md bg-muted/40 p-3 text-center">
                                        <Clock className="mx-auto h-4 w-4 text-muted-foreground" />
                                        <div className="mt-1 text-xs text-muted-foreground">Handed Over</div>
                                        <div className="mt-1 text-sm font-medium">
                                            {h.handed_over_at ? formatDateTime(h.handed_over_at) : '---'}
                                        </div>
                                    </div>
                                    {h.accepted_at && (
                                        <div className="rounded-md bg-muted/40 p-3 text-center">
                                            <CheckCircle className="mx-auto h-4 w-4 text-status-success" />
                                            <div className="mt-1 text-xs text-muted-foreground">Accepted At</div>
                                            <div className="mt-1 text-sm font-medium">{formatDateTime(h.accepted_at)}</div>
                                        </div>
                                    )}
                                    {h.odometer_km != null && (
                                        <div className="rounded-md bg-muted/40 p-3 text-center">
                                            <div className="text-xs text-muted-foreground">Odometer</div>
                                            <div className="mt-1 text-lg font-bold">{formatDistance((h.odometer_km))}</div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Fuel & Condition */}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Fuel className="h-4 w-4" />
                                        Fuel Level
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-3">
                                        <div className="relative h-16 w-8 rounded-md border-2 overflow-hidden">
                                            <div
                                                className={cn('absolute bottom-0 w-full transition-all', {
                                                    'bg-status-success': fuelPct > 50,
                                                    'bg-status-warning': fuelPct > 0 && fuelPct <= 50,
                                                    'bg-status-critical': fuelPct === 0,
                                                })}
                                                style={{ height: `${fuelPct}%` }}
                                            />
                                        </div>
                                        <span className="text-xl font-bold">{fuelLabel(h.fuel_level)}</span>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">Exterior</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <span className="text-lg">{conditionLabel(h.exterior_condition)}</span>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">Interior</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <span className="text-lg">{conditionLabel(h.interior_condition)}</span>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Damage Notes */}
                        {(h.damage_notes ?? []).length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                                        Damage Notes
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {(h.damage_notes ?? []).map((note, i) => (
                                            <div key={i} className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 dark:border-status-warning/30">
                                                <div className="text-sm font-medium">{note.area}</div>
                                                <div className="text-sm text-muted-foreground">{note.description}</div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Notes */}
                        {h.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-sm">{h.notes}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right: Checklist + Actions */}
                    <div className="space-y-4">
                        {/* Checklist Items */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Checklist Items</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {[
                                        { label: 'Keys Present', value: h.keys_present },
                                        { label: 'Documents Present', value: h.documents_present },
                                        { label: 'First Aid Kit', value: h.first_aid_kit },
                                        { label: 'Fire Extinguisher', value: h.fire_extinguisher },
                                    ].map((item) => (
                                        <div key={item.label} className={cn(
                                            'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                                            item.value ? 'border-status-success/30 bg-status-success-bg dark:border-status-success/30' : 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30'
                                        )}>
                                            {item.value ? (
                                                <Check className="h-5 w-5 text-status-success" />
                                            ) : (
                                                <XCircle className="h-5 w-5 text-status-critical" />
                                            )}
                                            <span className="text-sm font-medium">{item.label}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Accept / Dispute Actions */}
                        {canAcceptOrDispute && (
                            <Card className="border-2 border-status-warning/30 dark:border-status-warning/30">
                                <CardHeader>
                                    <CardTitle className="text-base">Accept or Dispute</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        Review the handover details. Accept if correct, or dispute with a reason.
                                    </p>
                                    <Button onClick={handleAccept} size="lg" className="w-full shadow-sm">
                                        <CheckCircle className="mr-2 h-5 w-5" />
                                        Accept Handover
                                    </Button>
                                    <div className="border-t pt-4">
                                        <form onSubmit={handleDispute} className="space-y-3">
                                            <label className="text-sm font-medium">Dispute Reason *</label>
                                            <textarea
                                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                rows={3}
                                                value={disputeForm.data.dispute_reason}
                                                onChange={(e) => disputeForm.setData('dispute_reason', e.target.value)}
                                                placeholder="Describe the discrepancy or issue..."
                                            />
                                            {disputeForm.errors.dispute_reason && (
                                                <p className="text-xs text-destructive">{disputeForm.errors.dispute_reason}</p>
                                            )}
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                size="lg"
                                                className="w-full"
                                                disabled={disputeForm.processing || !disputeForm.data.dispute_reason}
                                            >
                                                <XCircle className="mr-2 h-5 w-5" />
                                                Dispute Handover
                                            </Button>
                                        </form>
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
