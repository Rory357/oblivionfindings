import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import {
    AlertTriangle,
    Bell,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileWarning,
    MapPin,
    Pill,
    Shield,
    User,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Incident {
    id: string;
    source_type: 'client_incident' | 'medication_error' | 'safeguarding';
    source_id: number;
    title: string;
    description: string | null;
    severity: string;
    status: string;
    client_name: string;
    site_name: string;
    occurred_at: string | null;
    reporter_name: string;
    type_label: string;
    immediate_action: string | null;
    requires_followup: boolean;
    location: string | null;
}

interface PaginatedIncidents {
    data: Incident[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    incidents: PaginatedIncidents;
    filters: Record<string, string | undefined>;
    stats: {
        total: number;
        critical: number;
        high: number;
        unresolved: number;
    };
    sites: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    can: {
        createAlert: boolean;
    };
}

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    major: 'bg-status-warning text-white',
    high: 'bg-status-warning text-white',
    moderate: 'bg-status-warning text-black',
    medium: 'bg-status-warning text-black',
    minor: 'bg-status-info text-white',
    low: 'bg-status-info text-white',
    near_miss: 'bg-muted-foreground/80 text-white',
};

const statusColors: Record<string, string> = {
    submitted: 'bg-status-info-bg text-status-info',
    reported: 'bg-status-info-bg text-status-info',
    reviewed: 'bg-primary/10 text-primary',
    investigating: 'bg-status-warning-bg text-status-warning',
    triaged: 'bg-status-warning-bg text-status-warning',
    action_plan: 'bg-primary/10 text-primary',
    monitoring: 'bg-status-info-bg text-status-info',
    resolved: 'bg-status-success-bg text-status-success',
    closed: 'bg-muted text-foreground',
};

const sourceTypeConfig: Record<string, { label: string; color: string; icon: typeof AlertTriangle }> = {
    client_incident: { label: 'Client Incident', color: 'bg-status-info-bg text-status-info', icon: FileWarning },
    medication_error: { label: 'Medication Error', color: 'bg-status-critical-bg text-status-critical', icon: Pill },
    safeguarding: { label: 'Safeguarding', color: 'bg-primary/10 text-primary', icon: Shield },
};

const severityBorderColors: Record<string, string> = {
    critical: 'border-l-red-500',
    major: 'border-l-orange-500',
    high: 'border-l-orange-500',
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function truncate(text: string | null, length: number): string {
    if (!text) return '';
    return text.length > length ? text.slice(0, length) + '...' : text;
}

export default function IncidentTracker({ incidents, filters, stats, sites, clients, can }: Props) {
    const [selectedIncident, setSelectedIncident] = useState<Incident | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [alertDialogOpen, setAlertDialogOpen] = useState(false);
    const [alertTarget, setAlertTarget] = useState<Incident | null>(null);
    const [alertSeverity, setAlertSeverity] = useState('high');
    const [alertNotes, setAlertNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const refreshRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // 30-second auto-refresh
    useEffect(() => {
        refreshRef.current = setInterval(() => {
            router.reload({ only: ['incidents', 'stats'], preserveScroll: true });
        }, 30000);

        return () => {
            if (refreshRef.current) clearInterval(refreshRef.current);
        };
    }, []);

    const applyFilter = (key: string, value: string) => {
        const newFilters = { ...filters, [key]: value || undefined, page: undefined };
        router.get('/control-room/incidents', newFilters as any, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const goToPage = (page: number) => {
        router.get('/control-room/incidents', { ...filters, page: String(page) } as any, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const openDetail = (incident: Incident) => {
        setSelectedIncident(incident);
        setSheetOpen(true);
    };

    const openAlertDialog = (incident: Incident, e: React.MouseEvent) => {
        e.stopPropagation();
        setAlertTarget(incident);
        setAlertSeverity(incident.severity === 'near_miss' ? 'low' : incident.severity);
        setAlertNotes('');
        setAlertDialogOpen(true);
    };

    const submitCreateAlert = () => {
        if (!alertTarget) return;
        setSubmitting(true);
        router.post(
            '/control-room/incidents/create-alert',
            {
                source_type: alertTarget.source_type,
                source_id: alertTarget.source_id,
                severity: alertSeverity,
                notes: alertNotes,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setAlertDialogOpen(false);
                    setAlertTarget(null);
                },
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Incident Tracker', href: '#' },
            ]}
        >
            <Head title="Incident Tracker - Control Room" />
            <PageShell>
                <PageHeader
                    title="Incident Tracker"
                    description="Live feed of incidents across all modules."
                />

                {/* Stats Cards */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Incidents</CardTitle>
                            <FileWarning className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Critical</CardTitle>
                            <AlertTriangle className="h-4 w-4 text-status-critical" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-status-critical">{stats.critical}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">High</CardTitle>
                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-status-warning">{stats.high}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Unresolved</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.unresolved}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Bar */}
                <div className="flex flex-wrap items-center gap-3">
                    <Select
                        value={filters.source_type || 'all'}
                        onValueChange={(v) => applyFilter('source_type', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Sources</SelectItem>
                            <SelectItem value="client_incident">Client Incidents</SelectItem>
                            <SelectItem value="medication_error">Medication Errors</SelectItem>
                            <SelectItem value="safeguarding">Safeguarding</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.severity || 'all'}
                        onValueChange={(v) => applyFilter('severity', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="All Severity" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Severity</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) => applyFilter('status', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="reported">Reported</SelectItem>
                            <SelectItem value="reviewed">Reviewed</SelectItem>
                            <SelectItem value="investigating">Investigating</SelectItem>
                            <SelectItem value="resolved">Resolved</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.site_id || 'all'}
                        onValueChange={(v) => applyFilter('site_id', v === 'all' ? '' : v)}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Sites" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Sites</SelectItem>
                            {sites.map((site) => (
                                <SelectItem key={site.id} value={String(site.id)}>
                                    {site.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        placeholder="Search incidents..."
                        className="w-[200px]"
                        defaultValue={filters.search ?? ''}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                applyFilter('search', (e.target as HTMLInputElement).value);
                            }
                        }}
                    />

                    <Input
                        type="date"
                        className="w-[160px]"
                        defaultValue={filters.date_from ?? ''}
                        onChange={(e) => applyFilter('date_from', e.target.value)}
                    />
                    <span className="text-sm text-muted-foreground">to</span>
                    <Input
                        type="date"
                        className="w-[160px]"
                        defaultValue={filters.date_to ?? ''}
                        onChange={(e) => applyFilter('date_to', e.target.value)}
                    />

                    {Object.values(filters).some(Boolean) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.get('/control-room/incidents', {}, { preserveState: false })
                            }
                        >
                            Clear Filters
                        </Button>
                    )}
                </div>

                {/* Incident Feed */}
                <div className="flex flex-col gap-3">
                    {incidents.data.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12">
                                <FileWarning className="mb-3 h-10 w-10 text-muted-foreground" />
                                <p className="text-sm text-muted-foreground">
                                    No incidents found for the selected filters.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        incidents.data.map((incident) => {
                            const config = sourceTypeConfig[incident.source_type];
                            const Icon = config?.icon ?? AlertTriangle;
                            const borderClass = severityBorderColors[incident.severity] ?? '';

                            return (
                                <Card
                                    key={incident.id}
                                    className={`cursor-pointer border-l-4 transition-colors hover:bg-accent/50 ${borderClass || 'border-l-transparent'}`}
                                    onClick={() => openDetail(incident)}
                                >
                                    <CardContent className="flex items-start gap-4 py-4">
                                        <div className="mt-0.5 shrink-0">
                                            <Icon className="h-5 w-5 text-muted-foreground" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="mb-1 flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant="secondary"
                                                    className={config?.color ?? ''}
                                                >
                                                    {config?.label ?? incident.source_type}
                                                </Badge>
                                                <Badge className={severityColors[incident.severity] ?? 'bg-muted-foreground/80 text-white'}>
                                                    {incident.severity}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className={statusColors[incident.status] ?? ''}
                                                >
                                                    {incident.status.replace(/_/g, ' ')}
                                                </Badge>
                                                {incident.requires_followup && (
                                                    <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                                        Follow-up Required
                                                    </Badge>
                                                )}
                                            </div>
                                            <h3 className="text-sm font-semibold">
                                                {incident.title}
                                            </h3>
                                            <p className="mt-0.5 text-sm text-muted-foreground">
                                                {truncate(incident.description, 150)}
                                            </p>
                                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    {incident.client_name}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <MapPin className="h-3 w-3" />
                                                    {incident.site_name}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {formatRelativeTime(incident.occurred_at)}
                                                </span>
                                                <span>Reported by {incident.reporter_name}</span>
                                            </div>
                                        </div>
                                        {can.createAlert && (
                                            <div className="shrink-0">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={(e) => openAlertDialog(incident, e)}
                                                >
                                                    <Bell className="mr-1 h-3.5 w-3.5" />
                                                    Create Alert
                                                </Button>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>

                {/* Pagination */}
                {incidents.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {incidents.from} to {incidents.to} of {incidents.total} incidents
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={incidents.current_page <= 1}
                                onClick={() => goToPage(incidents.current_page - 1)}
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="text-sm">
                                Page {incidents.current_page} of {incidents.last_page}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={incidents.current_page >= incidents.last_page}
                                onClick={() => goToPage(incidents.current_page + 1)}
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </PageShell>

            {/* Detail Sheet */}
            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent className="overflow-y-auto sm:max-w-lg">
                    {selectedIncident && (
                        <>
                            <SheetHeader>
                                <SheetTitle>{selectedIncident.title}</SheetTitle>
                            </SheetHeader>
                            <div className="mt-6 space-y-6">
                                {/* Badges */}
                                <div className="flex flex-wrap gap-2">
                                    <Badge
                                        variant="secondary"
                                        className={sourceTypeConfig[selectedIncident.source_type]?.color ?? ''}
                                    >
                                        {sourceTypeConfig[selectedIncident.source_type]?.label ?? selectedIncident.source_type}
                                    </Badge>
                                    <Badge className={severityColors[selectedIncident.severity] ?? 'bg-muted-foreground/80 text-white'}>
                                        {selectedIncident.severity}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        className={statusColors[selectedIncident.status] ?? ''}
                                    >
                                        {selectedIncident.status.replace(/_/g, ' ')}
                                    </Badge>
                                </div>

                                {/* Details */}
                                <div className="space-y-3">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">Description</Label>
                                        <p className="mt-1 text-sm">
                                            {selectedIncident.description || 'No description provided.'}
                                        </p>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Client</Label>
                                            <p className="mt-1 text-sm font-medium">{selectedIncident.client_name}</p>
                                        </div>
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Site</Label>
                                            <p className="mt-1 text-sm font-medium">{selectedIncident.site_name}</p>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Type</Label>
                                            <p className="mt-1 text-sm font-medium">{selectedIncident.type_label}</p>
                                        </div>
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Reporter</Label>
                                            <p className="mt-1 text-sm font-medium">{selectedIncident.reporter_name}</p>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Occurred At</Label>
                                            <p className="mt-1 text-sm font-medium">
                                                {selectedIncident.occurred_at
                                                    ? new Date(selectedIncident.occurred_at).toLocaleString('en-NZ')
                                                    : '-'}
                                            </p>
                                        </div>
                                        {selectedIncident.location && (
                                            <div>
                                                <Label className="text-xs text-muted-foreground">Location</Label>
                                                <p className="mt-1 text-sm font-medium">{selectedIncident.location}</p>
                                            </div>
                                        )}
                                    </div>

                                    {selectedIncident.immediate_action && (
                                        <div>
                                            <Label className="text-xs text-muted-foreground">Immediate Action Taken</Label>
                                            <p className="mt-1 text-sm">{selectedIncident.immediate_action}</p>
                                        </div>
                                    )}

                                    {selectedIncident.requires_followup && (
                                        <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3">
                                            <p className="text-sm font-medium text-status-warning">
                                                Follow-up is required for this incident.
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* Timeline */}
                                <div>
                                    <Label className="text-xs text-muted-foreground">Timeline</Label>
                                    <div className="mt-2 space-y-3">
                                        <div className="flex gap-3">
                                            <div className="flex flex-col items-center">
                                                <div className="h-2 w-2 rounded-full bg-status-info" />
                                                <div className="w-px flex-1 bg-border" />
                                            </div>
                                            <div className="pb-3">
                                                <p className="text-sm font-medium">Occurred</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {selectedIncident.occurred_at
                                                        ? new Date(selectedIncident.occurred_at).toLocaleString('en-NZ')
                                                        : 'Unknown'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex gap-3">
                                            <div className="flex flex-col items-center">
                                                <div className="h-2 w-2 rounded-full bg-status-success" />
                                                <div className="w-px flex-1 bg-border" />
                                            </div>
                                            <div className="pb-3">
                                                <p className="text-sm font-medium">Reported</p>
                                                <p className="text-xs text-muted-foreground">
                                                    By {selectedIncident.reporter_name}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex gap-3">
                                            <div className="flex flex-col items-center">
                                                <div className={`h-2 w-2 rounded-full ${selectedIncident.status === 'closed' || selectedIncident.status === 'resolved' ? 'bg-status-success' : 'bg-status-warning'}`} />
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    Current Status: {selectedIncident.status.replace(/_/g, ' ')}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Actions */}
                                {can.createAlert && (
                                    <Button
                                        className="w-full"
                                        onClick={(e) => {
                                            setSheetOpen(false);
                                            openAlertDialog(selectedIncident, e as any);
                                        }}
                                    >
                                        <Bell className="mr-2 h-4 w-4" />
                                        Create Control Room Alert
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>

            {/* Create Alert Dialog */}
            <Dialog open={alertDialogOpen} onOpenChange={setAlertDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Create Control Room Alert</DialogTitle>
                        <DialogDescription>
                            Escalate this incident to the Control Room as an active alert.
                        </DialogDescription>
                    </DialogHeader>
                    {alertTarget && (
                        <div className="space-y-4">
                            <div className="rounded-md border bg-muted/50 p-3">
                                <p className="text-sm font-medium">{alertTarget.title}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {alertTarget.client_name} - {alertTarget.site_name}
                                </p>
                            </div>

                            <div>
                                <Label>Alert Severity</Label>
                                <Select value={alertSeverity} onValueChange={setAlertSeverity}>
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="critical">Critical</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="low">Low</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label>Notes (optional)</Label>
                                <Textarea
                                    className="mt-1"
                                    value={alertNotes}
                                    onChange={(e) => setAlertNotes(e.target.value)}
                                    placeholder="Any additional context for the alert..."
                                    rows={3}
                                />
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAlertDialogOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitCreateAlert} disabled={submitting}>
                            {submitting ? 'Creating...' : 'Create Alert'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
