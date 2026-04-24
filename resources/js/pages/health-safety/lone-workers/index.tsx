import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    MapPin,
    PhoneCall,
    Radio,
    Siren,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Staff = { id: number; name: string };
type Site = { id: number; name: string };
type Client = { id: number; name: string };

type Session = {
    id: number;
    user: Staff | null;
    site: Site | null;
    client: Client | null;
    started_at: string;
    expected_end_at: string | null;
    last_check_in_at: string | null;
    status: string;
    activity_description: string | null;
    check_in_interval_minutes: number | null;
    location: string | null;
};

type Alert = {
    id: number;
    session: Session | null;
    type: string;
    triggered_at: string;
    status: string;
    notes: string | null;
};

type Props = {
    sessions: {
        data: Session[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
    alerts: Alert[];
    stats: {
        active_sessions: number;
        overdue_check_ins: number;
        alerts_today: number;
        emergency_alerts: number;
    };
    staff: Staff[];
    sites: Site[];
    clients: Client[];
    can_manage: boolean;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const NONE = '__none__';

const fmtDate = (v: string | null) =>
    v
        ? new Date(v).toLocaleDateString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '-';

const fmtDateTime = (v: string | null) =>
    v
        ? new Date(v).toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '-';

const sessionStatusColor = (status: string) => {
    switch (status) {
        case 'active':
            return 'bg-status-success-bg text-status-success';
        case 'overdue':
            return 'bg-status-critical-bg text-status-critical animate-pulse';
        case 'completed':
            return 'bg-muted text-foreground';
        case 'emergency':
            return 'bg-status-critical text-white';
        default:
            return 'bg-muted text-foreground';
    }
};

const alertTypeColor = (type: string) => {
    switch (type) {
        case 'overdue_check_in':
            return 'bg-status-warning-bg text-status-warning';
        case 'emergency':
            return 'bg-status-critical-bg text-status-critical';
        case 'no_response':
            return 'bg-status-warning-bg text-status-warning';
        default:
            return 'bg-muted text-foreground';
    }
};

const alertStatusColor = (status: string) => {
    switch (status) {
        case 'active':
            return 'bg-status-critical-bg text-status-critical';
        case 'acknowledged':
            return 'bg-status-warning-bg text-status-warning';
        case 'resolved':
            return 'bg-status-success-bg text-status-success';
        default:
            return 'bg-muted text-foreground';
    }
};

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function LoneWorkerIndex({
    sessions,
    alerts,
    stats,
    staff,
    sites,
    clients,
    can_manage,
}: Props) {
    /* Dialog visibility states */
    const [startOpen, setStartOpen] = useState(false);
    const [checkInOpen, setCheckInOpen] = useState(false);
    const [checkInSessionId, setCheckInSessionId] = useState<number | null>(
        null,
    );
    const [endOpen, setEndOpen] = useState(false);
    const [endSessionId, setEndSessionId] = useState<number | null>(null);
    const [emergencyOpen, setEmergencyOpen] = useState(false);
    const [emergencySessionId, setEmergencySessionId] = useState<number | null>(
        null,
    );
    const [ackOpen, setAckOpen] = useState(false);
    const [ackAlertId, setAckAlertId] = useState<number | null>(null);
    const [resolveOpen, setResolveOpen] = useState(false);
    const [resolveAlertId, setResolveAlertId] = useState<number | null>(null);

    /* Forms */
    const startForm = useForm({
        user_id: '',
        site_id: '',
        client_id: '',
        expected_end_at: '',
        activity_description: '',
        check_in_interval_minutes: '30',
        location: '',
    });

    const checkInForm = useForm({
        status: 'ok',
        notes: '',
    });

    const ackForm = useForm({ notes: '' });
    const resolveForm = useForm({ notes: '' });

    /* Handlers */
    const submitStart = () => {
        startForm.post('/health-safety/lone-workers/sessions', {
            onSuccess: () => {
                setStartOpen(false);
                startForm.reset();
            },
        });
    };

    const submitCheckIn = () => {
        if (!checkInSessionId) return;
        checkInForm.post(
            `/health-safety/lone-workers/sessions/${checkInSessionId}/check-in`,
            {
                onSuccess: () => {
                    setCheckInOpen(false);
                    checkInForm.reset();
                },
            },
        );
    };

    const submitEnd = () => {
        if (!endSessionId) return;
        router.post(
            `/health-safety/lone-workers/sessions/${endSessionId}/end`,
            {},
            {
                onSuccess: () => setEndOpen(false),
            },
        );
    };

    const submitEmergency = () => {
        if (!emergencySessionId) return;
        router.post(
            `/health-safety/lone-workers/sessions/${emergencySessionId}/emergency`,
            {},
            {
                onSuccess: () => setEmergencyOpen(false),
            },
        );
    };

    const submitAcknowledge = () => {
        if (!ackAlertId) return;
        ackForm.post(
            `/health-safety/lone-workers/alerts/${ackAlertId}/acknowledge`,
            {
                onSuccess: () => {
                    setAckOpen(false);
                    ackForm.reset();
                },
            },
        );
    };

    const submitResolve = () => {
        if (!resolveAlertId) return;
        resolveForm.post(
            `/health-safety/lone-workers/alerts/${resolveAlertId}/resolve`,
            {
                onSuccess: () => {
                    setResolveOpen(false);
                    resolveForm.reset();
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Lone Worker Safety',
                    href: '/health-safety/lone-workers',
                },
            ]}
        >
            <Head title="Lone Worker Safety" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Lone Worker Safety"
                    description="Monitor active lone worker sessions, check-ins, and emergency alerts"
                    icon={<Radio className="h-7 w-7 text-white" />}
                    stats={[
                        {
                            label: 'Active Sessions',
                            value: stats.active_sessions,
                        },
                        {
                            label: 'Overdue Check-ins',
                            value: stats.overdue_check_ins,
                        },
                        { label: 'Alerts Today', value: stats.alerts_today },
                        { label: 'Emergency', value: stats.emergency_alerts },
                    ]}
                    actions={
                        can_manage ? (
                            <Button
                                size="sm"
                                onClick={() => setStartOpen(true)}
                            >
                                <Radio className="mr-1.5 h-4 w-4" />
                                Start Session
                            </Button>
                        ) : undefined
                    }
                />

                {/* Active Sessions Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Active Sessions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pr-4 pb-2 font-medium">
                                            Worker
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Site / Client
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Started
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Expected End
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Last Check-in
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Status
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sessions.data.map((s) => (
                                        <tr
                                            key={s.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2.5 pr-4">
                                                <div className="font-medium">
                                                    {s.user?.name ?? '-'}
                                                </div>
                                                {s.location && (
                                                    <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                        <MapPin className="h-3 w-3" />
                                                        {s.location}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-xs">
                                                {s.site?.name ?? '-'}
                                                {s.client && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        / {s.client.name}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-xs">
                                                {fmtDateTime(s.started_at)}
                                            </td>
                                            <td className="py-2.5 pr-4 text-xs">
                                                {fmtDateTime(s.expected_end_at)}
                                            </td>
                                            <td className="py-2.5 pr-4 text-xs">
                                                {fmtDateTime(
                                                    s.last_check_in_at,
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <Badge
                                                    className={sessionStatusColor(
                                                        s.status,
                                                    )}
                                                >
                                                    {s.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </td>
                                            <td className="py-2.5">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {can_manage &&
                                                        (s.status ===
                                                            'active' ||
                                                            s.status ===
                                                                'overdue') && (
                                                            <>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => {
                                                                        setCheckInSessionId(
                                                                            s.id,
                                                                        );
                                                                        setCheckInOpen(
                                                                            true,
                                                                        );
                                                                    }}
                                                                >
                                                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                    Check In
                                                                </Button>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => {
                                                                        setEndSessionId(
                                                                            s.id,
                                                                        );
                                                                        setEndOpen(
                                                                            true,
                                                                        );
                                                                    }}
                                                                >
                                                                    <XCircle className="mr-1 h-3 w-3" />
                                                                    End
                                                                </Button>
                                                                <Button
                                                                    variant="destructive"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => {
                                                                        setEmergencySessionId(
                                                                            s.id,
                                                                        );
                                                                        setEmergencyOpen(
                                                                            true,
                                                                        );
                                                                    }}
                                                                >
                                                                    <Siren className="mr-1 h-3 w-3" />
                                                                    Emergency
                                                                </Button>
                                                            </>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!sessions.data.length && (
                                <div className="py-6 text-center text-sm text-muted-foreground">
                                    No active lone worker sessions.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {sessions?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {sessions.links.map((l) => (
                            <Button
                                type="button"
                                key={l.label}
                                disabled={!l.url}
                                variant={l.active ? 'secondary' : 'outline'}
                                size="sm"
                                className="text-xs"
                                onClick={() =>
                                    l.url &&
                                    router.get(
                                        l.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}

                {/* Recent Alerts */}
                {alerts.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-base font-semibold">
                            Recent Alerts
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {alerts.map((alert) => (
                                <Card
                                    key={alert.id}
                                    className={
                                        alert.type === 'emergency'
                                            ? 'border-status-critical/30 bg-status-critical-bg'
                                            : ''
                                    }
                                >
                                    <CardContent className="pt-5">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="space-y-1.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        className={alertTypeColor(
                                                            alert.type,
                                                        )}
                                                    >
                                                        {alert.type.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                    <Badge
                                                        className={alertStatusColor(
                                                            alert.status,
                                                        )}
                                                    >
                                                        {alert.status}
                                                    </Badge>
                                                </div>
                                                {alert.session?.user && (
                                                    <div className="text-sm font-medium">
                                                        {
                                                            alert.session.user
                                                                .name
                                                        }
                                                    </div>
                                                )}
                                                {alert.session?.site && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {
                                                            alert.session.site
                                                                .name
                                                        }
                                                    </div>
                                                )}
                                                <div className="text-xs text-muted-foreground">
                                                    Triggered:{' '}
                                                    {fmtDateTime(
                                                        alert.triggered_at,
                                                    )}
                                                </div>
                                                {alert.notes && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {alert.notes}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                        {can_manage &&
                                            alert.status === 'active' && (
                                                <div className="mt-3 flex gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-7 text-xs"
                                                        onClick={() => {
                                                            setAckAlertId(
                                                                alert.id,
                                                            );
                                                            setAckOpen(true);
                                                        }}
                                                    >
                                                        Acknowledge
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-7 text-xs"
                                                        onClick={() => {
                                                            setResolveAlertId(
                                                                alert.id,
                                                            );
                                                            setResolveOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        Resolve
                                                    </Button>
                                                </div>
                                            )}
                                        {can_manage &&
                                            alert.status === 'acknowledged' && (
                                                <div className="mt-3">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-7 text-xs"
                                                        onClick={() => {
                                                            setResolveAlertId(
                                                                alert.id,
                                                            );
                                                            setResolveOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        Resolve
                                                    </Button>
                                                </div>
                                            )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* ============================================================ */}
            {/*  Dialogs                                                      */}
            {/* ============================================================ */}

            {/* Start Session Dialog */}
            <Dialog open={startOpen} onOpenChange={setStartOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Start Lone Worker Session</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label>Worker</Label>
                            <Select
                                value={startForm.data.user_id}
                                onValueChange={(v) =>
                                    startForm.setData('user_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select staff member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {staff.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {startForm.errors.user_id && (
                                <p className="mt-1 text-xs text-status-critical">
                                    {startForm.errors.user_id}
                                </p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Site (optional)</Label>
                                <Select
                                    value={startForm.data.site_id || NONE}
                                    onValueChange={(v) =>
                                        startForm.setData(
                                            'site_id',
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            None
                                        </SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Client (optional)</Label>
                                <Select
                                    value={startForm.data.client_id || NONE}
                                    onValueChange={(v) =>
                                        startForm.setData(
                                            'client_id',
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            None
                                        </SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Expected End</Label>
                                <Input
                                    type="datetime-local"
                                    value={startForm.data.expected_end_at}
                                    onChange={(e) =>
                                        startForm.setData(
                                            'expected_end_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                {startForm.errors.expected_end_at && (
                                    <p className="mt-1 text-xs text-status-critical">
                                        {startForm.errors.expected_end_at}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Check-in Interval</Label>
                                <Select
                                    value={
                                        startForm.data.check_in_interval_minutes
                                    }
                                    onValueChange={(v) =>
                                        startForm.setData(
                                            'check_in_interval_minutes',
                                            v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="15">
                                            Every 15 minutes
                                        </SelectItem>
                                        <SelectItem value="30">
                                            Every 30 minutes
                                        </SelectItem>
                                        <SelectItem value="60">
                                            Every 60 minutes
                                        </SelectItem>
                                        <SelectItem value="120">
                                            Every 2 hours
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div>
                            <Label>Location</Label>
                            <Input
                                value={startForm.data.location}
                                onChange={(e) =>
                                    startForm.setData(
                                        'location',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 23 Queen Street, Hamilton"
                            />
                        </div>

                        <div>
                            <Label>Activity Description</Label>
                            <Textarea
                                value={startForm.data.activity_description}
                                onChange={(e) =>
                                    startForm.setData(
                                        'activity_description',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe the lone work activity"
                                rows={3}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setStartOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitStart}
                            disabled={startForm.processing}
                        >
                            Start Session
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Check In Dialog */}
            <Dialog open={checkInOpen} onOpenChange={setCheckInOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Lone Worker Check-in</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label>Status</Label>
                            <Select
                                value={checkInForm.data.status}
                                onValueChange={(v) =>
                                    checkInForm.setData('status', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ok">
                                        OK - All good
                                    </SelectItem>
                                    <SelectItem value="concern">
                                        Concern - Need assistance
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Notes (optional)</Label>
                            <Textarea
                                value={checkInForm.data.notes}
                                onChange={(e) =>
                                    checkInForm.setData('notes', e.target.value)
                                }
                                placeholder="Any additional notes"
                                rows={3}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCheckInOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitCheckIn}
                            disabled={checkInForm.processing}
                        >
                            Submit Check-in
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* End Session Confirmation Dialog */}
            <Dialog open={endOpen} onOpenChange={setEndOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>End Session</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to end this lone worker session?
                        The worker will no longer be monitored.
                    </p>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setEndOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submitEnd}>End Session</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Emergency Confirmation Dialog */}
            <Dialog open={emergencyOpen} onOpenChange={setEmergencyOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-status-critical">
                            <Siren className="h-5 w-5" />
                            Trigger Emergency Alert
                        </DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        This will immediately trigger an emergency alert for
                        this lone worker. Emergency contacts will be notified.
                        Are you sure you want to proceed?
                    </p>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setEmergencyOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={submitEmergency}>
                            <PhoneCall className="mr-1.5 h-4 w-4" />
                            Confirm Emergency
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Acknowledge Alert Dialog */}
            <Dialog open={ackOpen} onOpenChange={setAckOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Acknowledge Alert</DialogTitle>
                    </DialogHeader>
                    <div>
                        <Label>Notes (optional)</Label>
                        <Textarea
                            value={ackForm.data.notes}
                            onChange={(e) =>
                                ackForm.setData('notes', e.target.value)
                            }
                            placeholder="e.g. Contacted worker, awaiting response"
                            rows={3}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setAckOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitAcknowledge}
                            disabled={ackForm.processing}
                        >
                            Acknowledge
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Resolve Alert Dialog */}
            <Dialog open={resolveOpen} onOpenChange={setResolveOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Resolve Alert</DialogTitle>
                    </DialogHeader>
                    <div>
                        <Label>Resolution Notes</Label>
                        <Textarea
                            value={resolveForm.data.notes}
                            onChange={(e) =>
                                resolveForm.setData('notes', e.target.value)
                            }
                            placeholder="Describe the resolution"
                            rows={3}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setResolveOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitResolve}
                            disabled={resolveForm.processing}
                        >
                            Resolve
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
