import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    stay: any;
};

const statusColor: Record<string, string> = {
    admitted: 'bg-status-info-bg text-status-info',
    active: 'bg-status-success-bg text-status-success',
    extended: 'bg-status-warning-bg text-status-warning',
    discharged: 'bg-muted text-muted-foreground',
};

const moodLabels: Record<string, string> = {
    very_low: 'Very Low',
    low: 'Low',
    neutral: 'Neutral',
    good: 'Good',
    excellent: 'Excellent',
};

const riskStatusColor: Record<string, string> = {
    pending_review: 'bg-status-warning-bg text-status-warning',
    active: 'bg-status-critical-bg text-status-critical',
    modified: 'bg-status-warning-bg text-status-warning',
    suspended: 'bg-muted text-muted-foreground',
    completed: 'bg-status-success-bg text-status-success',
};

export default function RespiteStayShow({ stay }: Props) {
    const [extendOpen, setExtendOpen] = useState(false);
    const [dischargeOpen, setDischargeOpen] = useState(false);
    const [extendDate, setExtendDate] = useState('');
    const [dischargeSummary, setDischargeSummary] = useState('');

    const handleExtend = () => {
        if (extendDate) {
            router.post(`/respite/stays/${stay.id}/extend`, { new_end: `${extendDate}T12:00:00` });
            setExtendOpen(false);
        }
    };

    const handleDischarge = () => {
        if (dischargeSummary) {
            router.post(`/respite/stays/${stay.id}/discharge`, { discharge_summary: dischargeSummary });
            setDischargeOpen(false);
        }
    };

    const arrivalChecklist = stay.arrival_checklist || [];
    const dischargeChecklist = stay.discharge_checklist || [];
    const transport = stay.transport_arrangements || {};

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
            { title: `${stay.client?.first_name} ${stay.client?.last_name}`, href: `/respite/stays/${stay.id}` },
        ]}>
            <Head title={`Stay - ${stay.client?.first_name} ${stay.client?.last_name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/stays"
                        title={`${stay.client?.first_name ?? ''} ${stay.client?.last_name ?? ''}`.trim() || 'Stay'}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Badge className={statusColor[stay.status] || ''}>{stay.status}</Badge>
                                {stay.evidence_pack_id && <Badge variant="outline">Evidence Pack Linked</Badge>}
                            </div>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <div className="grid gap-4 md:grid-cols-2">
                    {/* Stay Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Stay Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="grid grid-cols-2 gap-1">
                                <span className="text-muted-foreground">Status</span>
                                <span className="capitalize">{stay.status}</span>
                                <span className="text-muted-foreground">Admitted</span>
                                <span>{formatDateTimeLong(stay.actual_start) || '—'}</span>
                                <span className="text-muted-foreground">End</span>
                                <span>{formatDateTimeLong(stay.actual_end) || '—'}</span>
                                <span className="text-muted-foreground">Created by</span>
                                <span>{stay.created_by_user?.name || '—'}</span>
                            </div>
                            {stay.discharge_summary && (
                                <div className="mt-3 rounded border p-2">
                                    <div className="text-xs font-medium text-muted-foreground">Discharge Summary</div>
                                    <p className="mt-1 text-sm">{stay.discharge_summary}</p>
                                </div>
                            )}
                            {stay.post_respite_summary && (
                                <div className="mt-3 rounded border p-2">
                                    <div className="text-xs font-medium text-muted-foreground">Post-Respite Summary</div>
                                    <p className="mt-1 text-sm">{stay.post_respite_summary}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Booking Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Booking</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {stay.booking ? (
                                <div className="grid grid-cols-2 gap-1">
                                    <span className="text-muted-foreground">Booking ID</span>
                                    <Link href={`/respite/bookings/${stay.booking.id}`} className="text-primary hover:underline">
                                        #{stay.booking.id}
                                    </Link>
                                    <span className="text-muted-foreground">Booked Start</span>
                                    <span>{formatDateTimeLong(stay.booking.start_at) || '—'}</span>
                                    <span className="text-muted-foreground">Booked End</span>
                                    <span>{formatDateTimeLong(stay.booking.end_at) || '—'}</span>
                                    <span className="text-muted-foreground">Coordinator</span>
                                    <span>{stay.booking.coordinator?.name || 'Unassigned'}</span>
                                    <span className="text-muted-foreground">Status</span>
                                    <Badge variant="outline" className="w-fit">{stay.booking.status}</Badge>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">No booking linked.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Transport Arrangements */}
                    {(transport.arrival_mode || transport.departure_mode || transport.notes) && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Transport Arrangements</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div className="grid grid-cols-2 gap-1">
                                    {transport.arrival_mode && (
                                        <>
                                            <span className="text-muted-foreground">Arrival Mode</span>
                                            <span className="capitalize">{transport.arrival_mode}</span>
                                        </>
                                    )}
                                    {transport.departure_mode && (
                                        <>
                                            <span className="text-muted-foreground">Departure Mode</span>
                                            <span className="capitalize">{transport.departure_mode}</span>
                                        </>
                                    )}
                                </div>
                                {transport.notes && <p className="mt-1">{transport.notes}</p>}
                            </CardContent>
                        </Card>
                    )}

                    {/* Evidence Pack */}
                    {stay.evidence_pack && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Evidence Pack</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div className="grid grid-cols-2 gap-1">
                                    <span className="text-muted-foreground">Status</span>
                                    <Badge variant="outline" className="w-fit">{stay.evidence_pack.status}</Badge>
                                </div>
                                <Link href={`/respite/stays/${stay.id}/evidence-pack`} className="mt-2 inline-block text-xs text-primary hover:underline">
                                    View Evidence Pack
                                </Link>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Arrival Checklist */}
                {arrivalChecklist.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Arrival Checklist</CardTitle>
                                {stay.arrival_checklist_complete && <Badge className="bg-status-success-bg text-status-success">Complete</Badge>}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-1 text-sm">
                                {arrivalChecklist.map((item: any, i: number) => (
                                    <li key={i} className="flex items-center gap-2">
                                        <span className={item.done ? 'text-status-success' : 'text-muted-foreground'}>{item.done ? '\u2713' : '\u25CB'}</span>
                                        <span>{item.label || item}</span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* Discharge Checklist */}
                {dischargeChecklist.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Discharge Checklist</CardTitle>
                                {stay.discharge_checklist_complete && <Badge className="bg-status-success-bg text-status-success">Complete</Badge>}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-1 text-sm">
                                {dischargeChecklist.map((item: any, i: number) => (
                                    <li key={i} className="flex items-center gap-2">
                                        <span className={item.done ? 'text-status-success' : 'text-muted-foreground'}>{item.done ? '\u2713' : '\u25CB'}</span>
                                        <span>{item.label || item}</span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* Actions */}
                {stay.status !== 'discharged' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {stay.status === 'admitted' && (
                                <Button size="sm" onClick={() => router.post(`/respite/stays/${stay.id}/check-in`)}>
                                    Check In
                                </Button>
                            )}
                            <Button size="sm" variant="outline" onClick={() => setExtendOpen(true)}>
                                Extend
                            </Button>
                            <Button size="sm" variant="destructive" onClick={() => setDischargeOpen(true)}>
                                Discharge
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* Daily Notes */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Daily Notes</CardTitle>
                            <Link href={`/respite/stays/${stay.id}/daily-notes`} className="text-xs text-primary hover:underline">
                                View All
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {stay.daily_notes?.length > 0 ? (
                            <div className="space-y-3">
                                {stay.daily_notes.slice(0, 5).map((note: any) => (
                                    <div key={note.id} className="rounded border p-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{note.note_date}</span>
                                                <Badge variant="outline" className="text-xs capitalize">{note.shift_period?.replace('_', ' ')}</Badge>
                                            </div>
                                            {note.incident_occurred && <Badge className="bg-status-critical-bg text-status-critical">Incident</Badge>}
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                            {note.mood && <span>Mood: {moodLabels[note.mood] || note.mood}</span>}
                                            {note.appetite && <span>Appetite: {moodLabels[note.appetite] || note.appetite}</span>}
                                            {note.sleep_quality && <span>Sleep: {moodLabels[note.sleep_quality] || note.sleep_quality}</span>}
                                            {note.engagement && <span>Engagement: {moodLabels[note.engagement] || note.engagement}</span>}
                                            {note.mobility && <span>Mobility: {note.mobility}</span>}
                                        </div>
                                        {note.observations && <p className="mt-2 text-muted-foreground">{note.observations}</p>}
                                        {note.activities && <p className="mt-1"><span className="font-medium">Activities:</span> {note.activities}</p>}
                                        {note.concerns && <p className="mt-1 text-status-warning"><span className="font-medium">Concerns:</span> {note.concerns}</p>}
                                    </div>
                                ))}
                                {stay.daily_notes.length > 5 && (
                                    <p className="text-xs text-muted-foreground">+ {stay.daily_notes.length - 5} more notes</p>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No daily notes recorded yet.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Handover Notes */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Handover Notes</CardTitle>
                            <Link href={`/respite/stays/${stay.id}/handover-notes`} className="text-xs text-primary hover:underline">
                                View All
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {stay.handovers?.length > 0 ? (
                            <div className="space-y-3">
                                {stay.handovers.slice(0, 5).map((h: any) => (
                                    <div key={h.id} className="rounded border p-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline" className="text-xs capitalize">{h.handover_type?.replace('_', ' ')}</Badge>
                                                {h.sensitive_flag && <Badge className="bg-status-critical-bg text-status-critical">Sensitive</Badge>}
                                            </div>
                                            <span className="text-xs text-muted-foreground">{formatDateTimeLong(h.created_at)}</span>
                                        </div>
                                        <p className="mt-2">{h.notes}</p>
                                        {h.acknowledged_at && (
                                            <p className="mt-1 text-xs text-status-success">Acknowledged {formatDateTimeLong(h.acknowledged_at)}</p>
                                        )}
                                    </div>
                                ))}
                                {stay.handovers.length > 5 && (
                                    <p className="text-xs text-muted-foreground">+ {stay.handovers.length - 5} more handover notes</p>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No handover notes recorded yet.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Communication Logs */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Communication Logs</CardTitle>
                            <Link href={`/respite/stays/${stay.id}/communication-logs`} className="text-xs text-primary hover:underline">
                                View All
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {stay.communications?.length > 0 ? (
                            <div className="space-y-3">
                                {stay.communications.slice(0, 5).map((c: any) => (
                                    <div key={c.id} className="rounded border p-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <Badge variant="outline" className="text-xs capitalize">{c.channel?.replace('_', ' ')}</Badge>
                                            <span className="text-xs text-muted-foreground">{formatDateTimeLong(c.occurred_at)}</span>
                                        </div>
                                        <p className="mt-2">{c.summary}</p>
                                        {c.participants?.length > 0 && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Participants: {c.participants.map((p: any) => typeof p === 'string' ? p : p.name).join(', ')}
                                            </p>
                                        )}
                                    </div>
                                ))}
                                {stay.communications.length > 5 && (
                                    <p className="text-xs text-muted-foreground">+ {stay.communications.length - 5} more logs</p>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No communication logs recorded yet.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Risk Plan Activations */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Risk Plan Activations</CardTitle>
                            <Link href={`/respite/stays/${stay.id}/risk-plan-activations`} className="text-xs text-primary hover:underline">
                                View All
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {stay.risk_plan_activations?.length > 0 ? (
                            <div className="space-y-3">
                                {stay.risk_plan_activations.map((rpa: any) => (
                                    <div key={rpa.id} className="rounded border p-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <span className="font-medium">{rpa.plan_name}</span>
                                                <Badge variant="outline" className="ml-2 text-xs capitalize">{rpa.plan_type?.replace('_', ' ')}</Badge>
                                            </div>
                                            <Badge className={riskStatusColor[rpa.status] || ''}>{rpa.status?.replace('_', ' ')}</Badge>
                                        </div>
                                        {rpa.triggers && <p className="mt-2 text-muted-foreground"><span className="font-medium">Triggers:</span> {rpa.triggers}</p>}
                                        {rpa.interventions && <p className="mt-1 text-muted-foreground"><span className="font-medium">Interventions:</span> {rpa.interventions}</p>}
                                        {rpa.activated_at && (
                                            <p className="mt-1 text-xs text-muted-foreground">Activated: {formatDateTimeLong(rpa.activated_at)}</p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No risk plans activated for this stay.</p>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Extend Dialog */}
            <Dialog open={extendOpen} onOpenChange={setExtendOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Extend Stay</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div>
                            <Label htmlFor="extend-date">New End Date</Label>
                            <Input id="extend-date" type="date" value={extendDate} onChange={(e) => setExtendDate(e.target.value)} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setExtendOpen(false)}>Cancel</Button>
                        <Button onClick={handleExtend} disabled={!extendDate}>Confirm Extension</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Discharge Dialog */}
            <Dialog open={dischargeOpen} onOpenChange={setDischargeOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Discharge Stay</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div>
                            <Label htmlFor="discharge-summary">Discharge Summary</Label>
                            <Textarea
                                id="discharge-summary"
                                value={dischargeSummary}
                                onChange={(e) => setDischargeSummary(e.target.value)}
                                placeholder="Enter discharge summary..."
                                rows={4}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDischargeOpen(false)}>Cancel</Button>
                        <Button variant="destructive" onClick={handleDischarge} disabled={!dischargeSummary}>Confirm Discharge</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
