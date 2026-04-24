import FleetHero from '@/components/fleet-hero';
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
import { Head, router, useForm } from '@inertiajs/react';
import {
    Link2,
    Link2Off,
    Loader2,
    Radio,
    UserPlus,
} from 'lucide-react';

type ClientOption = {
    id: number;
    name: string;
    house: string;
    is_tracked: boolean;
};

type TrackerOption = {
    id: number;
    name: string;
    serial: string | null;
    mac: string | null;
    provider: string | null;
    status: string;
    meta: Record<string, any> | null;
};

type AssignedTracker = {
    id: number;
    name: string;
    serial: string | null;
    provider: string | null;
    status: string;
    client_id: number;
    client_name: string;
    client_house: string;
    battery: number | null;
};

type Props = {
    clients: ClientOption[];
    available_trackers: TrackerOption[];
    assigned_trackers: AssignedTracker[];
    can: {
        manage: boolean;
    };
};

export default function ResidentTrackingAssign({ clients, available_trackers, assigned_trackers, can }: Props) {
    const form = useForm({
        client_id: '',
        tracker_id: '',
    });

    const unassignableClients = (clients ?? []).filter((c) => !c.is_tracked);
    const selectedTracker = (available_trackers ?? []).find((t) => String(t.id) === form.data.tracker_id);

    const handleAssign = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/resident-tracking/assign', {
            preserveScroll: true,
        });
    };

    const handleUnassign = (trackerId: number) => {
        router.post(`/fleet-assets/resident-tracking/${trackerId}/unassign`, {}, {
            preserveScroll: true,
        });
    };

    if (!can.manage) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    { title: 'Resident Tracking', href: '/fleet-assets/resident-tracking' },
                    { title: 'Assign Tracker', href: '#' },
                ]}
            >
                <Head title="Assign Tracker" />
                <PageShell>
                    <FleetHero
                        title="Assign Tracker Device"
                        subtitle="Link a personal tracker to a resident"
                        backHref="/fleet-assets/resident-tracking"
                        backLabel="Back to Tracking"
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">View-only</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Assigning or unassigning trackers requires fleet manager access.
                            </p>
                        </CardContent>
                    </Card>
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Resident Tracking', href: '/fleet-assets/resident-tracking' },
                { title: 'Assign Tracker', href: '#' },
            ]}
        >
            <Head title="Assign Tracker" />
            <PageShell>
                <FleetHero
                    title="Assign Tracker Device"
                    subtitle="Link a personal tracker to a resident"
                    backHref="/fleet-assets/resident-tracking"
                    backLabel="Back to Tracking"
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Assign Form */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <UserPlus className="h-4 w-4" />
                                Assign New Tracker
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleAssign} className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Resident</Label>
                                    <Select
                                        value={form.data.client_id}
                                        onValueChange={(v) => form.setData('client_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a resident..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {unassignableClients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name} ({c.house})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.client_id && (
                                        <p className="text-xs text-destructive">{form.errors.client_id}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label>Tracker Device</Label>
                                    <Select
                                        value={form.data.tracker_id}
                                        onValueChange={(v) => form.setData('tracker_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select an available tracker..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(available_trackers ?? []).map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>
                                                    {t.name} {t.serial ? `(${t.serial})` : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.tracker_id && (
                                        <p className="text-xs text-destructive">{form.errors.tracker_id}</p>
                                    )}
                                </div>

                                {/* Selected device details */}
                                {selectedTracker && (
                                    <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
                                        <p className="text-sm font-medium">Device Details</p>
                                        <dl className="grid grid-cols-2 gap-2 text-sm">
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Name</dt>
                                                <dd className="font-medium">{selectedTracker.name}</dd>
                                            </div>
                                            {selectedTracker.serial && (
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Serial / IMEI</dt>
                                                    <dd className="font-medium">{selectedTracker.serial}</dd>
                                                </div>
                                            )}
                                            {selectedTracker.provider && (
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Vendor</dt>
                                                    <dd className="font-medium capitalize">{selectedTracker.provider}</dd>
                                                </div>
                                            )}
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Status</dt>
                                                <dd>
                                                    <Badge variant="secondary" className="text-xs capitalize">
                                                        {selectedTracker.status}
                                                    </Badge>
                                                </dd>
                                            </div>
                                            {selectedTracker.meta?.battery != null && (
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Battery</dt>
                                                    <dd className="font-medium">{selectedTracker.meta.battery}%</dd>
                                                </div>
                                            )}
                                        </dl>
                                    </div>
                                )}

                                <Button
                                    type="submit"
                                    disabled={form.processing || !form.data.client_id || !form.data.tracker_id}
                                    className="w-full"
                                >
                                    {form.processing ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Link2 className="mr-2 h-4 w-4" />
                                    )}
                                    Assign Tracker
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Currently Assigned */}
                    <Card className="flex flex-col">
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span className="flex items-center gap-2">
                                    <Radio className="h-4 w-4" />
                                    Currently Assigned
                                </span>
                                <Badge variant="secondary">{(assigned_trackers ?? []).length}</Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex-1 overflow-y-auto p-0" style={{ maxHeight: '500px' }}>
                            <div className="divide-y">
                                {(assigned_trackers ?? []).length === 0 ? (
                                    <div className="p-6 text-center text-sm text-muted-foreground">
                                        No trackers currently assigned.
                                    </div>
                                ) : (
                                    (assigned_trackers ?? []).map((tracker) => (
                                        <div key={tracker.id} className="flex items-center gap-3 px-4 py-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium">{tracker.client_name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {tracker.client_house} &middot; {tracker.name}
                                                    {tracker.serial ? ` (${tracker.serial})` : ''}
                                                </p>
                                                <div className="mt-1 flex items-center gap-2">
                                                    <Badge
                                                        variant="secondary"
                                                        className={`text-[10px] ${
                                                            tracker.status === 'online'
                                                                ? 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success'
                                                                : ''
                                                        }`}
                                                    >
                                                        {tracker.status}
                                                    </Badge>
                                                    {tracker.battery != null && (
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {tracker.battery}% battery
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleUnassign(tracker.id)}
                                                className="text-destructive hover:bg-destructive/10"
                                            >
                                                <Link2Off className="mr-1 h-3.5 w-3.5" />
                                                Unassign
                                            </Button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
