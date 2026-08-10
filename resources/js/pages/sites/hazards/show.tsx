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
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Calendar,
    CheckCircle2,
    Clock,
    FileText,
    Image,
    Lock,
    MapPin,
    Shield,
    User,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

type Site = {
    id: number;
    name: string;
    type: string;
};

type UserType = {
    id: number;
    name: string;
};

type Hazard = {
    id: number;
    reference_number: string;
    site: Site;
    hazard_type: string;
    custom_hazard_type?: string;
    severity: string;
    likelihood: string;
    risk_rating: string;
    description: string;
    location?: string;
    photo_paths?: string[];
    immediate_action_applied: boolean;
    immediate_action_taken?: string;
    reported_by: UserType;
    assigned_to?: UserType | null;
    assigned_at?: string;
    status: 'open' | 'in_progress' | 'mitigated' | 'closed';
    due_date?: string;
    resolution_summary?: string;
    closed_at?: string;
    created_at: string;
};

type Props = {
    hazard: Hazard;
    users: UserType[];
    canAssign: boolean;
    canClose: boolean;
};

const RISK_MATRIX: Record<string, Record<string, string>> = {
    low: {
        rare: 'low',
        unlikely: 'low',
        possible: 'medium',
        likely: 'medium',
        almost_certain: 'high',
    },
    medium: {
        rare: 'low',
        unlikely: 'medium',
        possible: 'medium',
        likely: 'high',
        almost_certain: 'high',
    },
    high: {
        rare: 'medium',
        unlikely: 'medium',
        possible: 'high',
        likely: 'high',
        almost_certain: 'extreme',
    },
    critical: {
        rare: 'high',
        unlikely: 'high',
        possible: 'extreme',
        likely: 'extreme',
        almost_certain: 'extreme',
    },
};

const sevKeys = ['low', 'medium', 'high', 'critical'];
const likKeys = ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'];

const riskBarColors: Record<string, string> = {
    extreme: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-warning',
    low: 'bg-status-success',
};

const severityConfig: Record<string, { bg: string; text: string }> = {
    low: { bg: 'bg-status-success-bg', text: 'text-status-success' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    high: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    critical: { bg: 'bg-status-critical-bg', text: 'text-status-critical' },
};

const statusConfig: Record<
    string,
    { bg: string; text: string; icon: typeof Clock }
> = {
    open: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        icon: AlertTriangle,
    },
    in_progress: {
        bg: 'bg-status-info-bg',
        text: 'text-status-info',
        icon: Clock,
    },
    mitigated: {
        bg: 'bg-primary/10',
        text: 'text-primary',
        icon: CheckCircle2,
    },
    closed: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        icon: CheckCircle2,
    },
};

const riskConfig: Record<string, { bg: string; text: string }> = {
    low: { bg: 'bg-status-success-bg', text: 'text-status-success' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    high: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    extreme: { bg: 'bg-status-critical-bg', text: 'text-status-critical' },
};

const WORKFLOW_STEPS = [
    { key: 'open', label: 'Open', icon: AlertTriangle },
    { key: 'in_progress', label: 'In Progress', icon: Clock },
    { key: 'mitigated', label: 'Mitigated', icon: Shield },
    { key: 'closed', label: 'Closed', icon: Lock },
];

function matrixCellColor(rating: string) {
    switch (rating) {
        case 'extreme':
            return 'bg-status-critical text-white';
        case 'high':
            return 'bg-status-warning text-white';
        case 'medium':
            return 'bg-status-warning-bg text-status-warning';
        default:
            return 'bg-status-success-bg text-status-success';
    }
}

export default function HazardShow({
    hazard,
    users,
    canAssign,
    canClose,
}: Props) {
    const [showAssignDialog, setShowAssignDialog] = useState(false);
    const [showCloseDialog, setShowCloseDialog] = useState(false);

    const assignForm = useForm({
        assigned_to_user_id: hazard.assigned_to?.id?.toString() || '',
    });

    const closeForm = useForm({
        resolution_summary: '',
    });

    const sev = severityConfig[hazard.severity] ?? severityConfig.low;
    const stat = statusConfig[hazard.status] ?? statusConfig.open;
    const risk = riskConfig[hazard.risk_rating] ?? riskConfig.low;
    const StatusIcon = stat.icon;

    const isOverdue =
        hazard.due_date &&
        new Date(hazard.due_date) < new Date() &&
        !['closed', 'mitigated'].includes(hazard.status);

    const stepIndex = WORKFLOW_STEPS.findIndex((s) => s.key === hazard.status);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: hazard.site.name, href: `/sites/${hazard.site.id}` },
                { title: 'Hazards', href: `/sites/${hazard.site.id}/hazards` },
                {
                    title: hazard.reference_number,
                    href: `/hazards/${hazard.id}`,
                },
            ]}
        >
            <Head title={`Hazard ${hazard.reference_number}`} />

            <div className="mx-auto max-w-4xl space-y-6 pb-8">
                {/* Back button */}
                <Link
                    href={`/sites/${hazard.site.id}/hazards`}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Back to Hazards
                </Link>

                {/* Header card */}
                <Card className="overflow-hidden">
                    <div
                        className={`h-2 ${riskBarColors[hazard.risk_rating] ?? 'bg-muted'}`}
                    />
                    <CardContent className="pt-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                    <span className="text-lg font-semibold">
                                        {hazard.reference_number}
                                    </span>
                                    <span className="text-muted-foreground">
                                        |
                                    </span>
                                    <span className="text-lg capitalize">
                                        {hazard.custom_hazard_type ||
                                            hazard.hazard_type.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                    </span>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}
                                    >
                                        {hazard.severity}
                                    </Badge>
                                    <Badge
                                        className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}
                                    >
                                        <StatusIcon className="mr-1 h-3 w-3" />
                                        {hazard.status.replace(/_/g, ' ')}
                                    </Badge>
                                    <Badge
                                        className={`${risk.bg} ${risk.text} border-0 text-[10px] font-medium`}
                                    >
                                        {hazard.risk_rating} risk
                                    </Badge>
                                    {isOverdue && (
                                        <Badge className="border-0 bg-status-critical-bg text-[10px] font-medium text-status-critical">
                                            <Clock className="mr-1 h-3 w-3" />
                                            Overdue
                                        </Badge>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {hazard.status !== 'closed' && canAssign && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setShowAssignDialog(true)
                                        }
                                    >
                                        <User className="mr-1 h-4 w-4" />
                                        {hazard.assigned_to
                                            ? 'Reassign'
                                            : 'Assign'}
                                    </Button>
                                )}
                                {['open', 'in_progress', 'mitigated'].includes(
                                    hazard.status,
                                ) &&
                                    canClose && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setShowCloseDialog(true)
                                            }
                                        >
                                            <CheckCircle2 className="mr-1 h-4 w-4" />
                                            Close
                                        </Button>
                                    )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Status timeline */}
                <Card>
                    <CardContent className="pt-5">
                        <div className="flex items-center justify-between">
                            {WORKFLOW_STEPS.map((step, idx) => {
                                const StepIcon = step.icon;
                                const isReached = idx <= stepIndex;
                                const isCurrent = idx === stepIndex;
                                return (
                                    <div
                                        key={step.key}
                                        className="flex flex-1 items-center"
                                    >
                                        <div className="flex flex-col items-center gap-1">
                                            <div
                                                className={`flex h-9 w-9 items-center justify-center rounded-full border-2 transition-colors ${
                                                    isCurrent
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : isReached
                                                          ? 'border-primary/30 bg-primary/10 text-primary'
                                                          : 'border-muted bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                <StepIcon className="h-4 w-4" />
                                            </div>
                                            <span
                                                className={`text-xs font-medium ${isCurrent ? 'text-primary' : isReached ? 'text-primary/70' : 'text-muted-foreground'}`}
                                            >
                                                {step.label}
                                            </span>
                                        </div>
                                        {idx < WORKFLOW_STEPS.length - 1 && (
                                            <div
                                                className={`mx-2 h-0.5 flex-1 ${isReached && idx < stepIndex ? 'bg-primary/30' : 'bg-muted'}`}
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Risk matrix visual */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                                Risk Matrix
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-start gap-4">
                                <div className="flex-1 overflow-x-auto">
                                    <table className="w-full border-collapse text-[10px]">
                                        <thead>
                                            <tr>
                                                <th className="p-1.5" />
                                                {likKeys.map((l) => (
                                                    <th
                                                        key={l}
                                                        className="p-1.5 text-center font-medium text-muted-foreground capitalize"
                                                    >
                                                        {
                                                            l
                                                                .replace(
                                                                    '_',
                                                                    ' ',
                                                                )
                                                                .split(' ')[0]
                                                        }
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {[...sevKeys].reverse().map((s) => (
                                                <tr key={s}>
                                                    <td className="p-1.5 pr-2 text-right font-medium text-muted-foreground capitalize">
                                                        {s}
                                                    </td>
                                                    {likKeys.map((l) => {
                                                        const cellRating =
                                                            RISK_MATRIX[s]?.[
                                                                l
                                                            ] ?? 'low';
                                                        const isActive =
                                                            s ===
                                                                hazard.severity &&
                                                            l ===
                                                                hazard.likelihood;
                                                        return (
                                                            <td
                                                                key={l}
                                                                className={`rounded p-1.5 text-center ${matrixCellColor(cellRating)} ${
                                                                    isActive
                                                                        ? 'text-xs font-bold ring-2 ring-ring ring-offset-1'
                                                                        : ''
                                                                }`}
                                                            >
                                                                {cellRating
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="space-y-2 text-xs">
                                    <div>
                                        <div className="text-muted-foreground">
                                            Severity
                                        </div>
                                        <Badge
                                            className={`${sev.bg} ${sev.text} border-0 text-[10px]`}
                                        >
                                            {hazard.severity}
                                        </Badge>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">
                                            Likelihood
                                        </div>
                                        <span className="font-medium capitalize">
                                            {hazard.likelihood.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </span>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground">
                                            Risk Rating
                                        </div>
                                        <Badge
                                            className={`${risk.bg} ${risk.text} border-0 text-[10px] font-semibold`}
                                        >
                                            {hazard.risk_rating.toUpperCase()}
                                        </Badge>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <div className="mb-1 text-xs text-muted-foreground">
                                    Description
                                </div>
                                <p className="text-sm whitespace-pre-wrap">
                                    {hazard.description}
                                </p>
                            </div>
                            {hazard.location && (
                                <div className="flex items-center gap-2 text-sm">
                                    <MapPin className="h-4 w-4 text-muted-foreground" />
                                    <span>{hazard.location}</span>
                                </div>
                            )}
                            <div className="flex items-center gap-2 text-sm">
                                <User className="h-4 w-4 text-muted-foreground" />
                                <span>
                                    Reported by {hazard.reported_by.name}
                                </span>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                                <Calendar className="h-4 w-4 text-muted-foreground" />
                                <span>
                                    {formatDateTimeLong(hazard.created_at)}
                                </span>
                            </div>
                            {hazard.due_date && (
                                <div
                                    className={`flex items-center gap-2 text-sm ${isOverdue ? 'font-medium text-status-critical' : ''}`}
                                >
                                    <Clock className="h-4 w-4" />
                                    <span>
                                        Due{' '}
                                        {new Date(
                                            hazard.due_date,
                                        ).toLocaleDateString()}
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Photos */}
                    {hazard.photo_paths && hazard.photo_paths.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Image className="h-4 w-4 text-muted-foreground" />
                                    Photos ({hazard.photo_paths.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {hazard.photo_paths.map((path, idx) => (
                                        <div
                                            key={idx}
                                            className="aspect-square overflow-hidden rounded-lg bg-muted"
                                        >
                                            <img
                                                src={`/storage/${path}`}
                                                alt={`Hazard photo ${idx + 1}`}
                                                className="h-full w-full object-cover"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Assignment */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <User className="h-4 w-4 text-muted-foreground" />
                                Assignment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-3">
                                <div
                                    className={`flex h-10 w-10 items-center justify-center rounded-full ${hazard.assigned_to ? 'bg-status-info-bg text-status-info' : 'bg-muted text-muted-foreground'}`}
                                >
                                    <User className="h-5 w-5" />
                                </div>
                                <div>
                                    <div className="text-sm font-medium">
                                        {hazard.assigned_to?.name ||
                                            'Unassigned'}
                                    </div>
                                    {hazard.assigned_at && (
                                        <div className="text-xs text-muted-foreground">
                                            Assigned on{' '}
                                            {new Date(
                                                hazard.assigned_at,
                                            ).toLocaleDateString()}
                                        </div>
                                    )}
                                </div>
                            </div>
                            {hazard.status !== 'closed' && canAssign && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                    onClick={() => setShowAssignDialog(true)}
                                >
                                    {hazard.assigned_to
                                        ? 'Reassign'
                                        : 'Assign someone'}
                                </Button>
                            )}
                        </CardContent>
                    </Card>

                    {/* Immediate Action */}
                    {hazard.immediate_action_applied && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Zap className="h-4 w-4 text-muted-foreground" />
                                    Immediate Action Taken
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm whitespace-pre-wrap">
                                    {hazard.immediate_action_taken ||
                                        'No details provided'}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Resolution */}
                    {hazard.resolution_summary && (
                        <Card className="border-status-success/30 bg-status-success-bg">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm text-status-success">
                                    <CheckCircle2 className="h-4 w-4" />
                                    Resolution
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm whitespace-pre-wrap">
                                    {hazard.resolution_summary}
                                </p>
                                {hazard.closed_at && (
                                    <div className="mt-3 text-xs text-muted-foreground">
                                        Closed on{' '}
                                        {new Date(
                                            hazard.closed_at,
                                        ).toLocaleDateString()}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            {/* Assign Dialog */}
            <Dialog open={showAssignDialog} onOpenChange={setShowAssignDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Assign Hazard</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            assignForm.post(`/hazards/${hazard.id}/assign`, {
                                onSuccess: () => setShowAssignDialog(false),
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="space-y-1.5">
                            <Label>Assign to</Label>
                            <Select
                                value={
                                    assignForm.data.assigned_to_user_id ||
                                    undefined
                                }
                                onValueChange={(v) =>
                                    assignForm.setData('assigned_to_user_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select user..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((u) => (
                                        <SelectItem
                                            key={u.id}
                                            value={u.id.toString()}
                                        >
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setShowAssignDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={assignForm.processing}
                            >
                                Assign
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Close Dialog */}
            <Dialog open={showCloseDialog} onOpenChange={setShowCloseDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close Hazard</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            closeForm.post(`/hazards/${hazard.id}/close`, {
                                onSuccess: () => setShowCloseDialog(false),
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="space-y-1.5">
                            <Label>Resolution Summary</Label>
                            <Textarea
                                value={closeForm.data.resolution_summary}
                                onChange={(e) =>
                                    closeForm.setData(
                                        'resolution_summary',
                                        e.target.value,
                                    )
                                }
                                rows={4}
                                placeholder="Describe how the hazard was resolved..."
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setShowCloseDialog(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={closeForm.processing}
                            >
                                Close Hazard
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
