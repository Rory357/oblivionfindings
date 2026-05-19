import SupportingEvidenceDialog, {
    type SupportingEvidenceAttachment,
} from '@/components/medications/SupportingEvidenceDialog';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    Eye,
    Paperclip,
    Plus,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    errors: { data: any[]; links: any };
    stats: {
        total_open: number;
        critical: number;
        this_month: number;
        resolved_this_month: number;
    };
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    can: {
        manage_evidence: boolean;
    };
    filters: {
        client_id?: string;
        severity?: string;
        error_type?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
        tab?: string;
    };
};

const errorTypeLabels: Record<string, string> = {
    wrong_medication: 'Wrong Medication',
    wrong_client: 'Wrong Client',
    wrong_dose: 'Wrong Dose',
    wrong_time: 'Wrong Time',
    wrong_route: 'Wrong Route',
    omission: 'Omission',
    unauthorised: 'Unauthorised',
    documentation: 'Documentation',
    other: 'Other',
};

const severityColors: Record<string, string> = {
    near_miss: 'bg-muted text-foreground',
    minor: 'bg-status-info-bg text-status-info',
    moderate: 'bg-status-warning-bg text-status-warning',
    major: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

const statusColors: Record<string, string> = {
    reported: 'bg-status-warning-bg text-status-warning',
    investigating: 'bg-status-info-bg text-status-info',
    resolved: 'bg-status-success-bg text-status-success',
    closed: 'bg-muted text-muted-foreground',
};

function ReportErrorDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        client_id: '',
        client_medication_id: '',
        error_type: '',
        severity: '',
        description: '',
        immediate_action: '',
        contributing_factors: '',
        create_incident: false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/errors', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="mr-1 h-4 w-4" /> Report Error
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Report Medication Error</DialogTitle>
                    <DialogDescription>
                        Capture the medication error, severity, immediate
                        response, and whether it should create an incident.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="error_client_id">Client *</Label>
                            <Select
                                value={form.data.client_id}
                                onValueChange={(v) =>
                                    form.setData('client_id', v)
                                }
                            >
                                <SelectTrigger id="error_client_id">
                                    <SelectValue placeholder="Select client" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={c.id.toString()}
                                        >
                                            {c.last_name}, {c.first_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.client_id && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.client_id}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="client_medication_id">
                                Medication (optional)
                            </Label>
                            <Input
                                id="client_medication_id"
                                placeholder="Medication name or ID"
                                value={form.data.client_medication_id}
                                onChange={(e) =>
                                    form.setData(
                                        'client_medication_id',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="error_type">Error Type *</Label>
                            <Select
                                value={form.data.error_type}
                                onValueChange={(v) =>
                                    form.setData('error_type', v)
                                }
                            >
                                <SelectTrigger id="error_type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(errorTypeLabels).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            {form.errors.error_type && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.error_type}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="severity">Severity *</Label>
                            <Select
                                value={form.data.severity}
                                onValueChange={(v) =>
                                    form.setData('severity', v)
                                }
                            >
                                <SelectTrigger id="severity">
                                    <SelectValue placeholder="Select severity" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="near_miss">
                                        Near Miss
                                    </SelectItem>
                                    <SelectItem value="minor">Minor</SelectItem>
                                    <SelectItem value="moderate">
                                        Moderate
                                    </SelectItem>
                                    <SelectItem value="major">Major</SelectItem>
                                    <SelectItem value="critical">
                                        Critical
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.severity && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.severity}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="description">Description *</Label>
                        <Textarea
                            id="description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="Describe the medication error in detail..."
                        />
                        {form.errors.description && (
                            <p className="text-xs text-status-critical">
                                {form.errors.description}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="immediate_action">
                            Immediate Action Taken
                        </Label>
                        <Textarea
                            id="immediate_action"
                            rows={2}
                            value={form.data.immediate_action}
                            onChange={(e) =>
                                form.setData('immediate_action', e.target.value)
                            }
                            placeholder="What immediate action was taken?"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="contributing_factors">
                            Contributing Factors
                        </Label>
                        <Textarea
                            id="contributing_factors"
                            rows={2}
                            value={form.data.contributing_factors}
                            onChange={(e) =>
                                form.setData(
                                    'contributing_factors',
                                    e.target.value,
                                )
                            }
                            placeholder="What factors contributed to this error?"
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="create_incident"
                            checked={form.data.create_incident}
                            onChange={(e) =>
                                form.setData(
                                    'create_incident',
                                    e.target.checked,
                                )
                            }
                            className="h-4 w-4 rounded border-border"
                        />
                        <Label
                            htmlFor="create_incident"
                            className="text-sm font-normal"
                        >
                            Also create a linked incident report
                        </Label>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Reporting...' : 'Report Error'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ReviewDialog({ error }: { error: any }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        review_notes: '',
        status: 'investigating',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/emar/errors/${error.id}/review`, {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" className="h-7 text-xs">
                    <Eye className="mr-1 h-3 w-3" /> Review
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Review Medication Error</DialogTitle>
                    <DialogDescription>
                        Document the investigation notes and update the current
                        review status.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="review_notes">Review Notes *</Label>
                        <Textarea
                            id="review_notes"
                            rows={4}
                            value={form.data.review_notes}
                            onChange={(e) =>
                                form.setData('review_notes', e.target.value)
                            }
                            placeholder="Enter your review notes..."
                        />
                        {form.errors.review_notes && (
                            <p className="text-xs text-status-critical">
                                {form.errors.review_notes}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="review_status">Status</Label>
                        <Select
                            value={form.data.status}
                            onValueChange={(v) => form.setData('status', v)}
                        >
                            <SelectTrigger id="review_status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="reported">
                                    Reported
                                </SelectItem>
                                <SelectItem value="investigating">
                                    Investigating
                                </SelectItem>
                                <SelectItem value="resolved">
                                    Resolved
                                </SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Submit Review'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ResolveDialog({ error }: { error: any }) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        outcome: '',
        preventive_actions: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/emar/errors/${error.id}/resolve`, {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" className="h-7 text-xs">
                    <CheckCircle className="mr-1 h-3 w-3" /> Resolve
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Resolve Medication Error</DialogTitle>
                    <DialogDescription>
                        Record the outcome and preventive actions before
                        closing this medication error.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="outcome">Outcome *</Label>
                        <Textarea
                            id="outcome"
                            rows={3}
                            value={form.data.outcome}
                            onChange={(e) =>
                                form.setData('outcome', e.target.value)
                            }
                            placeholder="Describe the outcome of this error..."
                        />
                        {form.errors.outcome && (
                            <p className="text-xs text-status-critical">
                                {form.errors.outcome}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="preventive_actions">
                            Preventive Actions *
                        </Label>
                        <Textarea
                            id="preventive_actions"
                            rows={3}
                            value={form.data.preventive_actions}
                            onChange={(e) =>
                                form.setData(
                                    'preventive_actions',
                                    e.target.value,
                                )
                            }
                            placeholder="What actions will be taken to prevent recurrence?"
                        />
                        {form.errors.preventive_actions && (
                            <p className="text-xs text-status-critical">
                                {form.errors.preventive_actions}
                            </p>
                        )}
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Resolving...' : 'Mark Resolved'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function MedicationErrors({
    errors,
    stats,
    clients,
    staff,
    can,
    filters,
}: Props) {
    const [evidenceTarget, setEvidenceTarget] = useState<any | null>(null);
    const [attachmentOverrides, setAttachmentOverrides] = useState<
        Record<number, SupportingEvidenceAttachment[]>
    >({});

    function getAttachments(error: any): SupportingEvidenceAttachment[] {
        return attachmentOverrides[error.id] ?? error.attachments ?? [];
    }

    function updateAttachments(
        errorId: number,
        attachments: SupportingEvidenceAttachment[],
    ) {
        setAttachmentOverrides((current) => ({
            ...current,
            [errorId]: attachments,
        }));
    }

    function updateFilter(key: string, value: string) {
        router.get(
            '/emar/errors',
            { ...filters, [key]: value || undefined },
            { preserveState: true },
        );
    }

    function switchTab(tab: string) {
        router.get(
            '/emar/errors',
            { ...filters, tab: tab === 'all' ? undefined : tab },
            { preserveState: true },
        );
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Errors" />
            <PageHero variant="compact"
                title="Medication Errors"
                description="Record, review, and resolve medication errors. Track trends and implement preventive actions."
                backHref="/emar"
            />
            <PageShell>
                {/* Stats Cards */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-warning-bg p-2">
                                <Clock className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.total_open}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Total Open
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-critical-bg p-2">
                                <ShieldAlert className="h-5 w-5 text-status-critical" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.critical}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Critical
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-info-bg p-2">
                                <AlertTriangle className="h-5 w-5 text-status-info" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.this_month}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    This Month
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-success-bg p-2">
                                <CheckCircle className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.resolved_this_month}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Resolved This Month
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="mb-6 flex flex-wrap gap-3">
                    <Input
                        type="date"
                        className="w-40"
                        placeholder="From date"
                        value={filters.date_from ?? ''}
                        onChange={(e) =>
                            updateFilter('date_from', e.target.value)
                        }
                    />
                    <Input
                        type="date"
                        className="w-40"
                        placeholder="To date"
                        value={filters.date_to ?? ''}
                        onChange={(e) =>
                            updateFilter('date_to', e.target.value)
                        }
                    />
                    <Select
                        value={filters.client_id ?? ''}
                        onValueChange={(v) => updateFilter('client_id', v)}
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="All clients" />
                        </SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>
                                    {c.last_name}, {c.first_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.severity ?? ''}
                        onValueChange={(v) => updateFilter('severity', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All severities" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="near_miss">Near Miss</SelectItem>
                            <SelectItem value="minor">Minor</SelectItem>
                            <SelectItem value="moderate">Moderate</SelectItem>
                            <SelectItem value="major">Major</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.error_type ?? ''}
                        onValueChange={(v) => updateFilter('error_type', v)}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(errorTypeLabels).map(
                                ([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status ?? ''}
                        onValueChange={(v) => updateFilter('status', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="reported">Reported</SelectItem>
                            <SelectItem value="investigating">
                                Investigating
                            </SelectItem>
                            <SelectItem value="resolved">Resolved</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Tabs
                    defaultValue={filters.tab ?? 'all'}
                    onValueChange={switchTab}
                >
                    <TabsList className="mb-4">
                        <TabsTrigger value="all">All Errors</TabsTrigger>
                        <TabsTrigger value="open">Open</TabsTrigger>
                        <TabsTrigger value="critical">Critical</TabsTrigger>
                        <TabsTrigger value="resolved">Resolved</TabsTrigger>
                    </TabsList>

                    {['all', 'open', 'critical', 'resolved'].map((tab) => (
                        <TabsContent key={tab} value={tab}>
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between pb-3">
                                    <CardTitle className="text-base">
                                        Medication Errors
                                    </CardTitle>
                                    <ReportErrorDialog clients={clients} />
                                </CardHeader>
                                <CardContent className="p-0">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="p-3 text-left font-medium">
                                                    Date
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Client
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Medication
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Type
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Severity
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Description
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Reported By
                                                </th>
                                                <th className="p-3 text-left font-medium">
                                                    Status
                                                </th>
                                                <th className="p-3 text-right font-medium">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {errors.data.map((err: any) => (
                                                <tr
                                                    key={err.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="p-3 text-xs">
                                                        {err.reported_at
                                                            ? new Date(
                                                                  err.reported_at,
                                                              ).toLocaleDateString(
                                                                  'en-NZ',
                                                              )
                                                            : '—'}
                                                    </td>
                                                    <td className="p-3">
                                                        {err.client?.last_name},{' '}
                                                        {err.client?.first_name}
                                                    </td>
                                                    <td className="p-3 text-xs">
                                                        {err.medication?.name ??
                                                            '—'}
                                                    </td>
                                                    <td className="p-3">
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {errorTypeLabels[
                                                                err.error_type
                                                            ] ?? err.error_type}
                                                        </Badge>
                                                    </td>
                                                    <td className="p-3">
                                                        <Badge
                                                            className={`text-xs ${severityColors[err.severity] ?? ''}`}
                                                        >
                                                            {err.severity?.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    </td>
                                                    <td
                                                        className="max-w-[200px] truncate p-3 text-xs"
                                                        title={err.description}
                                                    >
                                                        {err.description}
                                                    </td>
                                                    <td className="p-3 text-xs">
                                                        {err.reported_by_user
                                                            ?.name ?? '—'}
                                                    </td>
                                                    <td className="p-3">
                                                        <Badge
                                                            className={`text-xs ${statusColors[err.status] ?? ''}`}
                                                        >
                                                            {err.status}
                                                        </Badge>
                                                        {getAttachments(err)
                                                            .length > 0 && (
                                                            <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                                <Paperclip className="h-3 w-3" />
                                                                {
                                                                    getAttachments(
                                                                        err,
                                                                    ).length
                                                                }{' '}
                                                                evidence file(s)
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-7 text-xs"
                                                                onClick={() =>
                                                                    setEvidenceTarget(
                                                                        err,
                                                                    )
                                                                }
                                                            >
                                                                <Paperclip className="mr-1 h-3 w-3" />
                                                                Evidence
                                                            </Button>
                                                            {(err.status ===
                                                                'reported' ||
                                                                err.status ===
                                                                    'investigating') && (
                                                                <>
                                                                    <ReviewDialog
                                                                        error={
                                                                            err
                                                                        }
                                                                    />
                                                                    <ResolveDialog
                                                                        error={
                                                                            err
                                                                        }
                                                                    />
                                                                </>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                            {errors.data.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={9}
                                                        className="p-6 text-center text-muted-foreground"
                                                    >
                                                        No medication errors
                                                        found.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    ))}
                </Tabs>

                <SupportingEvidenceDialog
                    isOpen={!!evidenceTarget}
                    onClose={() => setEvidenceTarget(null)}
                    clientId={evidenceTarget?.client?.id ?? null}
                    targetType="error"
                    targetId={evidenceTarget?.id ?? null}
                    title="Medication Error Evidence"
                    subject={
                        evidenceTarget
                            ? `${evidenceTarget.client?.last_name}, ${evidenceTarget.client?.first_name} - ${errorTypeLabels[evidenceTarget.error_type] ?? evidenceTarget.error_type}`
                            : ''
                    }
                    attachments={
                        evidenceTarget ? getAttachments(evidenceTarget) : []
                    }
                    canManage={can.manage_evidence}
                    onAttachmentsChange={(attachments) => {
                        if (!evidenceTarget) {
                            return;
                        }

                        updateAttachments(evidenceTarget.id, attachments);
                    }}
                />
            </PageShell>
        </AppLayout>
    );
}
