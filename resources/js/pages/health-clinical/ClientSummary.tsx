import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import { Activity, ClipboardList, HeartPulse, ShieldAlert } from 'lucide-react';

type ClientRef = { id: number; first_name: string; last_name: string };

type MedicalProfile = {
    gp_name: string | null;
    gp_practice: string | null;
    allergies: string[] | null;
    disabilities: string[] | null;
    blood_type: string | null;
} | null;

type Observation = {
    id: number;
    observation_type: string;
    recorded_at: string;
    notes: string | null;
    recorder: { id: number; name: string } | null;
};

type Protocol = {
    id: number;
    observation_type: string;
    frequency: string;
    next_due_at: string | null;
    last_recorded_at: string | null;
    is_overdue: boolean;
    notes: string | null;
    created_by: string | null;
};

type ClinicalEvent = {
    id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    follow_up_required: boolean;
    follow_up_completed_at: string | null;
    reporter: { id: number; name: string } | null;
};

type Summary = {
    medical_profile: MedicalProfile;
    recent_observations: Observation[];
    active_protocols: Protocol[];
    recent_events: ClinicalEvent[];
};

type Props = {
    client: ClientRef;
    summary: Summary;
    observation_types: Record<string, string>;
    event_types: Record<string, string>;
};

const severityColor: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800',
    medium: 'bg-amber-100 text-amber-800',
    high: 'bg-orange-100 text-orange-800',
    critical: 'bg-red-100 text-red-800',
};

export default function ClientSummary({ client, summary, observation_types, event_types }: Props) {
    const name = `${client.first_name} ${client.last_name}`;

    return (
        <AppLayout>
            <Head title={`Health Summary — ${name}`} />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">{name}</h1>
                        <p className="mt-1 text-sm text-gray-500">Health & Clinical Summary</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={`/operations/clients/${client.id}`}>
                            <Button variant="outline" size="sm">Client Profile</Button>
                        </Link>
                        <Link href="/health-clinical">
                            <Button variant="outline" size="sm">Dashboard</Button>
                        </Link>
                    </div>
                </div>

                {/* Medical Profile */}
                {summary.medical_profile && (
                    <>
                        {summary.medical_profile.allergies && summary.medical_profile.allergies.length > 0 && (
                            <div className="flex items-center gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                                <ShieldAlert className="h-6 w-6 shrink-0 text-red-600" />
                                <div>
                                    <p className="text-sm font-bold text-red-800">Allergies</p>
                                    <p className="text-sm text-red-700">{summary.medical_profile.allergies.join(', ')}</p>
                                </div>
                            </div>
                        )}
                        {summary.medical_profile.gp_name && (
                            <Card className="border-emerald-200 bg-emerald-50/30">
                                <CardContent className="p-4">
                                    <p className="text-xs font-semibold uppercase tracking-wider text-emerald-600">GP / Primary Care</p>
                                    <p className="mt-1 text-sm font-medium">{summary.medical_profile.gp_name}</p>
                                    {summary.medical_profile.gp_practice && <p className="text-xs text-muted-foreground">{summary.medical_profile.gp_practice}</p>}
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Active Protocols */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ClipboardList className="h-4 w-4" /> Active Protocols ({summary.active_protocols.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {summary.active_protocols.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No active protocols.</p>
                            ) : (
                                <div className="space-y-2">
                                    {summary.active_protocols.map((p) => (
                                        <div key={p.id} className={`rounded-lg border p-3 ${p.is_overdue ? 'border-red-200 bg-red-50/40' : ''}`}>
                                            <div className="flex items-center justify-between">
                                                <Badge variant="secondary" className="text-xs">{observation_types[p.observation_type] ?? p.observation_type}</Badge>
                                                <div className="flex items-center gap-1">
                                                    <span className="text-xs capitalize text-muted-foreground">{p.frequency.replace('_', ' ')}</span>
                                                    {p.is_overdue && <Badge variant="destructive" className="text-xs">Overdue</Badge>}
                                                </div>
                                            </div>
                                            {p.next_due_at && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Next due: {new Date(p.next_due_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Observations */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <HeartPulse className="h-4 w-4" /> Recent Observations (7d)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {summary.recent_observations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No observations in the last 7 days.</p>
                            ) : (
                                <div className="space-y-2">
                                    {summary.recent_observations.map((obs) => (
                                        <div key={obs.id} className="flex items-start justify-between rounded-lg border p-3">
                                            <div>
                                                <Badge variant="secondary" className="text-xs">{observation_types[obs.observation_type] ?? obs.observation_type}</Badge>
                                                {obs.notes && <p className="mt-1 line-clamp-1 text-xs text-muted-foreground">{obs.notes}</p>}
                                            </div>
                                            <div className="text-right">
                                                <span className="text-xs text-muted-foreground">
                                                    {new Date(obs.recorded_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                </span>
                                                <p className="text-xs text-muted-foreground">{obs.recorder?.name}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Events */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Activity className="h-4 w-4" /> Recent Clinical Events (30d)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {summary.recent_events.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No clinical events in the last 30 days.</p>
                        ) : (
                            <div className="space-y-2">
                                {summary.recent_events.map((evt) => (
                                    <div key={evt.id} className="flex items-start justify-between rounded-lg border p-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <Badge className={`text-xs ${severityColor[evt.severity] ?? ''}`}>{evt.severity}</Badge>
                                                <Badge variant="outline" className="text-xs">{event_types[evt.event_type] ?? evt.event_type}</Badge>
                                                {evt.follow_up_required && !evt.follow_up_completed_at && (
                                                    <Badge variant="destructive" className="text-xs">Follow-up</Badge>
                                                )}
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{evt.description}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {new Date(evt.occurred_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
