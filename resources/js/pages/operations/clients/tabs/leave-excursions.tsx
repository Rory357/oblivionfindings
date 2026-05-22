import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    CalendarRange,
    Compass,
    MapPin,
    Plus,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';

export type LeaveItem = {
    id: number;
    starts_on?: string | null;
    ends_on?: string | null;
    destination?: string | null;
    support_required?: string | null;
    risks_and_mitigations?: string | null;
    emergency_contact?: string | null;
    status?: string | null;
    requester?: string | null;
    approver?: string | null;
    approved_at?: string | null;
    approval_notes?: string | null;
};

export type ExcursionItem = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    destination?: string | null;
    activity_description?: string | null;
    transport_method?: string | null;
    risk_assessment?: string | null;
    outcome_notes?: string | null;
    status?: string | null;
    requester?: string | null;
    approver?: string | null;
    approved_at?: string | null;
    approval_notes?: string | null;
};

type LeaveExcursionsTabProps = {
    clientId: number;
    leave?: LeaveItem[];
    excursions?: ExcursionItem[];
    canManage?: boolean;
};

function dateLabel(value?: string | null) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function dateTimeLabel(value?: string | null) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function statusBadge(status?: string | null): string {
    const s = (status ?? '').toLowerCase();
    if (s === 'approved' || s === 'completed')
        return 'bg-status-success-bg text-status-success';
    if (s === 'declined' || s === 'cancelled')
        return 'bg-status-critical-bg text-status-critical';
    if (s === 'requested' || s === 'proposed')
        return 'bg-status-warning-bg text-status-warning';
    return 'bg-muted text-muted-foreground';
}

function NewLeaveDialog({ clientId }: { clientId: number }) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({
        starts_on: '',
        ends_on: '',
        destination: '',
        support_required: '',
        risks_and_mitigations: '',
        emergency_contact: '',
    });

    const submit = () => {
        router.post(`/operations/clients/${clientId}/leave`, form, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Request leave
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request leave</DialogTitle>
                    <DialogDescription>
                        Planned time away from the service. Approval is tracked
                        and the timeline updates automatically.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label>Start date</Label>
                        <Input
                            type="date"
                            value={form.starts_on}
                            onChange={(e) =>
                                setForm({ ...form, starts_on: e.target.value })
                            }
                        />
                    </div>
                    <div>
                        <Label>End date</Label>
                        <Input
                            type="date"
                            value={form.ends_on}
                            onChange={(e) =>
                                setForm({ ...form, ends_on: e.target.value })
                            }
                        />
                    </div>
                </div>
                <Label className="mt-2">Destination</Label>
                <Input
                    value={form.destination}
                    onChange={(e) =>
                        setForm({ ...form, destination: e.target.value })
                    }
                />
                <Label className="mt-2">Support required while away</Label>
                <Textarea
                    rows={3}
                    value={form.support_required}
                    onChange={(e) =>
                        setForm({ ...form, support_required: e.target.value })
                    }
                />
                <Label className="mt-2">Risks & mitigations</Label>
                <Textarea
                    rows={3}
                    value={form.risks_and_mitigations}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            risks_and_mitigations: e.target.value,
                        })
                    }
                />
                <Label className="mt-2">Emergency contact</Label>
                <Input
                    value={form.emergency_contact}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            emergency_contact: e.target.value,
                        })
                    }
                />
                <DialogFooter>
                    <Button variant="ghost" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button onClick={submit}>Submit request</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function NewExcursionDialog({ clientId }: { clientId: number }) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({
        starts_at: '',
        ends_at: '',
        destination: '',
        activity_description: '',
        transport_method: '',
        risk_assessment: '',
    });

    const submit = () => {
        router.post(`/operations/clients/${clientId}/excursions`, form, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Plan excursion
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Plan an excursion</DialogTitle>
                    <DialogDescription>
                        Capture date, destination, transport, and risk
                        assessment. Outcome notes can be added after the
                        excursion.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label>Start</Label>
                        <Input
                            type="datetime-local"
                            value={form.starts_at}
                            onChange={(e) =>
                                setForm({ ...form, starts_at: e.target.value })
                            }
                        />
                    </div>
                    <div>
                        <Label>End</Label>
                        <Input
                            type="datetime-local"
                            value={form.ends_at}
                            onChange={(e) =>
                                setForm({ ...form, ends_at: e.target.value })
                            }
                        />
                    </div>
                </div>
                <Label className="mt-2">Destination</Label>
                <Input
                    value={form.destination}
                    onChange={(e) =>
                        setForm({ ...form, destination: e.target.value })
                    }
                />
                <Label className="mt-2">Activity description</Label>
                <Textarea
                    rows={3}
                    value={form.activity_description}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            activity_description: e.target.value,
                        })
                    }
                />
                <Label className="mt-2">Transport</Label>
                <Input
                    placeholder="e.g. Fleet van, taxi, family car"
                    value={form.transport_method}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            transport_method: e.target.value,
                        })
                    }
                />
                <Label className="mt-2">Risk assessment</Label>
                <Textarea
                    rows={3}
                    value={form.risk_assessment}
                    onChange={(e) =>
                        setForm({ ...form, risk_assessment: e.target.value })
                    }
                />
                <DialogFooter>
                    <Button variant="ghost" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button onClick={submit}>Propose excursion</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function LeaveExcursionsTab({
    clientId,
    leave = [],
    excursions = [],
    canManage = false,
}: LeaveExcursionsTabProps) {
    return (
        <div className="space-y-6" data-test="client-leave-excursions-tab">
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Leave & excursions
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Planned absences and activities. Approvals are
                            tracked and key events project to the timeline.
                        </p>
                    </div>
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <NewLeaveDialog clientId={clientId} />
                            <NewExcursionDialog clientId={clientId} />
                        </div>
                    ) : null}
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <CalendarRange className="h-4 w-4 text-primary" />
                        Leave requests
                        <Badge variant="outline" className="ml-auto">
                            {leave.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {leave.length > 0 ? (
                        leave.map((item) => (
                            <div
                                key={`leave-${item.id}`}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                className={cn(
                                                    statusBadge(item.status),
                                                    'capitalize',
                                                )}
                                            >
                                                {item.status ?? 'requested'}
                                            </Badge>
                                            {item.destination ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin className="h-3.5 w-3.5" />
                                                    {item.destination}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {dateLabel(item.starts_on)} —{' '}
                                            {dateLabel(item.ends_on)}
                                        </p>
                                    </div>
                                    {item.requester ? (
                                        <p className="text-xs text-muted-foreground">
                                            Requested by {item.requester}
                                        </p>
                                    ) : null}
                                </div>
                                {item.support_required ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Support:
                                        </span>{' '}
                                        {item.support_required}
                                    </p>
                                ) : null}
                                {item.risks_and_mitigations ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Risks:
                                        </span>{' '}
                                        {item.risks_and_mitigations}
                                    </p>
                                ) : null}
                                {item.emergency_contact ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Emergency contact:
                                        </span>{' '}
                                        {item.emergency_contact}
                                    </p>
                                ) : null}
                                {item.approval_notes ? (
                                    <p className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                                        <UserCheck className="mr-1 inline h-3 w-3" />
                                        {item.approver ?? 'Approver'}:{' '}
                                        {item.approval_notes}
                                    </p>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            icon={CalendarRange}
                            title="No leave requests yet"
                            description="Use the Request leave button to capture planned time away from the service."
                        />
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Compass className="h-4 w-4 text-primary" />
                        Excursions
                        <Badge variant="outline" className="ml-auto">
                            {excursions.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {excursions.length > 0 ? (
                        excursions.map((item) => (
                            <div
                                key={`ex-${item.id}`}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                className={cn(
                                                    statusBadge(item.status),
                                                    'capitalize',
                                                )}
                                            >
                                                {item.status ?? 'proposed'}
                                            </Badge>
                                            {item.destination ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin className="h-3.5 w-3.5" />
                                                    {item.destination}
                                                </span>
                                            ) : null}
                                            {item.transport_method ? (
                                                <Badge variant="outline">
                                                    {item.transport_method}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {dateTimeLabel(item.starts_at)}
                                            {item.ends_at
                                                ? ` — ${dateTimeLabel(item.ends_at)}`
                                                : ''}
                                        </p>
                                    </div>
                                    {item.requester ? (
                                        <p className="text-xs text-muted-foreground">
                                            Proposed by {item.requester}
                                        </p>
                                    ) : null}
                                </div>
                                {item.activity_description ? (
                                    <p className="mt-2 text-xs">
                                        <Sparkles className="mr-1 inline h-3 w-3" />
                                        {item.activity_description}
                                    </p>
                                ) : null}
                                {item.risk_assessment ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Risk:
                                        </span>{' '}
                                        {item.risk_assessment}
                                    </p>
                                ) : null}
                                {item.outcome_notes ? (
                                    <p className="mt-2 rounded-md bg-status-success-bg p-2 text-xs text-status-success">
                                        Outcome: {item.outcome_notes}
                                    </p>
                                ) : null}
                                {item.approval_notes ? (
                                    <p className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                                        <UserCheck className="mr-1 inline h-3 w-3" />
                                        {item.approver ?? 'Approver'}:{' '}
                                        {item.approval_notes}
                                    </p>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            icon={Compass}
                            title="No excursions planned"
                            description="Plan an outing or activity from the Plan excursion button."
                        />
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
