import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import {
    complete as completeObligation,
    index as complianceIndex,
} from '@/routes/governance/compliance';
import { upload as uploadEvidence } from '@/routes/governance/compliance/evidence';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    FileCheck,
    Upload,
    User,
} from 'lucide-react';
import { useState } from 'react';

interface Evidence {
    id: number;
    evidence_type: string;
    title: string;
    file_path: string;
    valid_until: string | null;
    uploaded_by: { name: string };
    uploaded_at: string;
}

interface Reminder {
    id: number;
    days_before_due: number;
    scheduled_at: string;
    status: string;
    sent_at: string | null;
}

interface Obligation {
    id: number;
    framework: string;
    obligation_code: string | null;
    obligation_title: string;
    description: string;
    requirements?: string | null;
    frequency: string;
    due_date: string;
    next_due_date: string | null;
    status: string;
    owner: { id: number; name: string } | null;
    completed_at: string | null;
    completed_by: { name: string } | null;
    completion_notes?: string | null;
    version_number?: number;
    parent_obligation_id?: number | null;
    parent_obligation?: { id: number; obligation_title: string; due_date: string } | null;
    recurrences?: Array<{ id: number; obligation_title: string; due_date: string; status: string }>;
    evidence_required: boolean;
    evidence_provided: boolean;
    sign_off_required: boolean;
    signed_off_at: string | null;
    signed_off_by: { name: string } | null;
    notes: string | null;
    evidence: Evidence[];
    reminders: Reminder[];
}

interface Props extends PageProps {
    obligation: Obligation;
}

export default function ComplianceShow({ auth, obligation }: Props) {
    const evidenceItems = obligation.evidence ?? [];
    const reminderItems = obligation.reminders ?? [];
    const [showUploadDialog, setShowUploadDialog] = useState(false);
    const [uploadForm, setUploadForm] = useState({
        evidence_type: 'document',
        title: '',
        description: '',
        valid_until: '',
        file: null as File | null,
    });
    const [submitting, setSubmitting] = useState(false);

    // Enhanced Completion Dialog State
    const [showCompleteDialog, setShowCompleteDialog] = useState(false);
    const [completionNotes, setCompletionNotes] = useState('');
    const [selectedEvidenceIds, setSelectedEvidenceIds] = useState<number[]>([]);
    const [completeSubmitting, setCompleteSubmitting] = useState(false);
    const [completeError, setCompleteError] = useState<string | null>(null);

    const isEvidenceExpired = (ev: Evidence) => {
        if (!ev.valid_until) return false;
        const validUntil = new Date(ev.valid_until);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return validUntil < today;
    };

    const validEvidenceItems = evidenceItems.filter((ev) => !isEvidenceExpired(ev));
    const hasValidEvidence = validEvidenceItems.length > 0;
    const canComplete = !obligation.evidence_required || hasValidEvidence;

    const openCompleteDialog = () => {
        setSelectedEvidenceIds(validEvidenceItems.map((e) => e.id));
        setCompletionNotes(obligation.completion_notes || '');
        setCompleteError(null);
        setShowCompleteDialog(true);
    };

    const handleComplete = async () => {
        setCompleteSubmitting(true);
        setCompleteError(null);
        try {
            await axios.post(
                completeObligation.url({ obligation: obligation.id }),
                {
                    evidence_ids: selectedEvidenceIds.length > 0 ? selectedEvidenceIds : undefined,
                    completion_notes: completionNotes || undefined,
                    expected_version: obligation.version_number ?? 1,
                },
            );
            setShowCompleteDialog(false);
            router.reload();
        } catch (error: any) {
            const msg =
                error.response?.data?.message ||
                'Failed to mark obligation complete.';
            setCompleteError(msg);
        } finally {
            setCompleteSubmitting(false);
        }
    };

    const getFrameworkLabel = (framework: string) => {
        const labels: Record<string, string> = {
            charities: 'Charities Services',
            nga_paerewa: 'Ngā Paerewa NZS 8134:2021',
            hdsa_safety: 'H&D Services (Safety) Act',
            privacy_act: 'Privacy Act 2020',
            hip_code: 'Health Information Privacy Code',
            hswa: 'Health and Safety at Work Act',
            employment: 'Employment Relations',
            funding_moh: 'MoH/Health NZ Funding',
            funding_msd: 'MSD Funding',
            funding_acc: 'ACC Funding',
        };
        return labels[framework] || framework;
    };

    const getStatusColor = (status: string) => {
        return governanceStatusColor(status);
        // legacy switch removed — see lib/governance-status.ts
    };

    const daysRemaining = () => {
        const due = new Date(obligation.due_date);
        const now = new Date();
        const diff = Math.ceil(
            (due.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
        );
        return diff;
    };

    const handleUpload = async () => {
        if (!uploadForm.file) return;
        setSubmitting(true);

        const formData = new FormData();
        formData.append('evidence_type', uploadForm.evidence_type);
        formData.append('title', uploadForm.title);
        formData.append('description', uploadForm.description || '');
        formData.append('file', uploadForm.file);
        if (uploadForm.valid_until) {
            formData.append('valid_until', uploadForm.valid_until);
        }

        try {
            await axios.post(
                uploadEvidence.url({ obligation: obligation.id }),
                formData,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                },
            );
            router.reload();
            setShowUploadDialog(false);
        } catch (error) {
            console.error('Upload failed:', error);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Compliance', href: '/governance/compliance' },
                {
                    title: 'Obligation',
                    href: `/governance/compliance/${obligation.id}`,
                },
            ]}
        >
            <Head title={obligation.obligation_title} />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref={complianceIndex.url()}
                        icon={FileCheck}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="compliance-heading"
                            >
                                {obligation.obligation_title}
                                <Badge variant="outline">
                                    {getFrameworkLabel(obligation.framework)}
                                </Badge>
                                {obligation.obligation_code && (
                                    <Badge variant="outline">
                                        {obligation.obligation_code}
                                    </Badge>
                                )}
                                <Badge
                                    className={getStatusColor(
                                        obligation.status,
                                    )}
                                >
                                    {obligation.status}
                                </Badge>
                            </span>
                        }
                        stats={[
                            {
                                label: 'Framework',
                                value: getFrameworkLabel(obligation.framework),
                            },
                            { label: 'Due', value: obligation.due_date },
                            { label: 'Status', value: obligation.status },
                            { label: 'Evidence', value: evidenceItems.length },
                        ]}
                        actions={
                            <div className="flex gap-2">
                                <Dialog
                                    open={showUploadDialog}
                                    onOpenChange={setShowUploadDialog}
                                >
                                    <DialogTrigger asChild>
                                        <Button variant="outline">
                                            <Upload className="mr-2 h-4 w-4" />
                                            Upload Evidence
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Upload Evidence
                                            </DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-4">
                                            <div>
                                                <Label>Evidence Type</Label>
                                                <Select
                                                    value={
                                                        uploadForm.evidence_type
                                                    }
                                                    onValueChange={(v) =>
                                                        setUploadForm({
                                                            ...uploadForm,
                                                            evidence_type: v,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="document">
                                                            Document
                                                        </SelectItem>
                                                        <SelectItem value="audit_report">
                                                            Audit Report
                                                        </SelectItem>
                                                        <SelectItem value="certification">
                                                            Certification
                                                        </SelectItem>
                                                        <SelectItem value="system_export">
                                                            System Export
                                                        </SelectItem>
                                                        <SelectItem value="attestation">
                                                            Attestation
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Title</Label>
                                                <Input
                                                    value={uploadForm.title}
                                                    onChange={(e) =>
                                                        setUploadForm({
                                                            ...uploadForm,
                                                            title: e.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder="Evidence title..."
                                                />
                                            </div>
                                            <div>
                                                <Label>File</Label>
                                                <Input
                                                    type="file"
                                                    onChange={(e) =>
                                                        setUploadForm({
                                                            ...uploadForm,
                                                            file:
                                                                e.target
                                                                    .files?.[0] ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>
                                                    Valid Until (optional)
                                                </Label>
                                                <Input
                                                    type="date"
                                                    value={
                                                        uploadForm.valid_until
                                                    }
                                                    onChange={(e) =>
                                                        setUploadForm({
                                                            ...uploadForm,
                                                            valid_until:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                onClick={handleUpload}
                                                disabled={
                                                    submitting ||
                                                    !uploadForm.file ||
                                                    !uploadForm.title
                                                }
                                            >
                                                {submitting
                                                    ? 'Uploading...'
                                                    : 'Upload'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                                {obligation.status !== 'complete' && (
                                    <Button
                                        onClick={openCompleteDialog}
                                        data-dusk="open-complete-dialog-button"
                                    >
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Mark Complete
                                    </Button>
                                )}
                                <Dialog
                                    open={showCompleteDialog}
                                    onOpenChange={setShowCompleteDialog}
                                >
                                    <DialogContent className="max-w-xl">
                                        <DialogHeader>
                                            <DialogTitle>
                                                Complete Compliance Obligation
                                            </DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-3">
                                            {completeError && (
                                                <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                                                    {completeError}
                                                </div>
                                            )}

                                            <div className="rounded-lg border bg-muted/40 p-3 space-y-1 text-sm">
                                                <p className="font-semibold text-foreground">
                                                    {obligation.obligation_title}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {getFrameworkLabel(obligation.framework)} &bull; Due: {obligation.due_date}
                                                </p>
                                                {obligation.requirements && (
                                                    <p className="text-xs text-muted-foreground pt-1 border-t border-border mt-1">
                                                        <span className="font-medium">Requirements:</span>{' '}
                                                        {obligation.requirements}
                                                    </p>
                                                )}
                                            </div>

                                            {obligation.evidence_required && !hasValidEvidence && (
                                                <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 space-y-3">
                                                    <div className="flex items-start gap-2">
                                                        <AlertTriangle className="h-5 w-5 text-status-critical shrink-0 mt-0.5" />
                                                        <div>
                                                            <p className="font-medium text-status-critical">
                                                                Valid Evidence Required
                                                            </p>
                                                            <p className="text-xs text-muted-foreground mt-0.5">
                                                                Evidence is mandatory to satisfy this compliance obligation, but no active, unexpired evidence is currently attached.
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="w-full"
                                                        onClick={() => {
                                                            setShowCompleteDialog(false);
                                                            setShowUploadDialog(true);
                                                        }}
                                                    >
                                                        <Upload className="mr-2 h-4 w-4" />
                                                        Upload Evidence First
                                                    </Button>
                                                </div>
                                            )}

                                            <div>
                                                <Label className="text-xs font-semibold uppercase text-muted-foreground">
                                                    Attached Evidence ({evidenceItems.length})
                                                </Label>
                                                {evidenceItems.length > 0 ? (
                                                    <div className="mt-2 space-y-2 max-h-48 overflow-y-auto pr-1">
                                                        {evidenceItems.map((ev) => {
                                                            const expired = isEvidenceExpired(ev);
                                                            const isChecked = selectedEvidenceIds.includes(ev.id);
                                                            return (
                                                                <label
                                                                    key={ev.id}
                                                                    className={cn(
                                                                        'flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
                                                                        expired
                                                                            ? 'opacity-60 bg-muted/30 cursor-not-allowed'
                                                                            : 'hover:bg-muted/40',
                                                                        isChecked &&
                                                                            !expired &&
                                                                            'border-primary/50 bg-primary/5',
                                                                    )}
                                                                >
                                                                    <Checkbox
                                                                        checked={isChecked}
                                                                        disabled={expired}
                                                                        onCheckedChange={(checked) => {
                                                                            if (checked) {
                                                                                setSelectedEvidenceIds([
                                                                                    ...selectedEvidenceIds,
                                                                                    ev.id,
                                                                                ]);
                                                                            } else {
                                                                                setSelectedEvidenceIds(
                                                                                    selectedEvidenceIds.filter(
                                                                                        (id) =>
                                                                                            id !==
                                                                                            ev.id,
                                                                                    ),
                                                                                );
                                                                            }
                                                                        }}
                                                                        className="mt-0.5"
                                                                    />
                                                                    <div className="flex-1 text-xs">
                                                                        <div className="flex items-center justify-between gap-2">
                                                                            <span className="font-medium text-foreground">
                                                                                {ev.title}
                                                                            </span>
                                                                            {expired ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="border-status-critical/30 text-status-critical text-[10px]"
                                                                                >
                                                                                    Expired ({ev.valid_until})
                                                                                </Badge>
                                                                            ) : ev.valid_until ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="border-status-success/30 text-status-success text-[10px]"
                                                                                >
                                                                                    Valid until {ev.valid_until}
                                                                                </Badge>
                                                                            ) : (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    Active
                                                                                </Badge>
                                                                            )}
                                                                        </div>
                                                                        <p className="text-muted-foreground capitalize mt-0.5">
                                                                            {ev.evidence_type.replace('_', ' ')} &bull; Uploaded by {ev.uploaded_by?.name || 'Unknown'}
                                                                        </p>
                                                                    </div>
                                                                </label>
                                                            );
                                                        })}
                                                    </div>
                                                ) : (
                                                    <p className="text-xs text-muted-foreground mt-2 italic">
                                                        No evidence files uploaded yet.
                                                    </p>
                                                )}
                                            </div>

                                            <div>
                                                <Label
                                                    htmlFor="completion-notes"
                                                    className="text-xs font-semibold uppercase text-muted-foreground"
                                                >
                                                    Completion Notes
                                                </Label>
                                                <Textarea
                                                    id="completion-notes"
                                                    value={completionNotes}
                                                    onChange={(e) =>
                                                        setCompletionNotes(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Add details on how this obligation was fulfilled, relevant findings, or actions taken..."
                                                    className="mt-1"
                                                    rows={3}
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter className="gap-2 sm:gap-0">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setShowCompleteDialog(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                onClick={handleComplete}
                                                disabled={
                                                    completeSubmitting ||
                                                    !canComplete
                                                }
                                                data-dusk="submit-complete-obligation-button"
                                            >
                                                {completeSubmitting
                                                    ? 'Completing...'
                                                    : 'Complete Obligation'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Main Content */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Description */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Description</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-foreground">
                                    {obligation.description}
                                </p>
                                {obligation.notes && (
                                    <div className="mt-4 rounded-lg bg-muted p-4">
                                        <p className="text-sm font-medium text-foreground">
                                            Notes
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {obligation.notes}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Evidence */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Evidence</CardTitle>
                                <CardDescription>
                                    {obligation.evidence_required
                                        ? 'Evidence is required for this obligation'
                                        : 'Evidence is optional'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {evidenceItems.length > 0 ? (
                                    <div className="space-y-3">
                                        {evidenceItems.map((ev) => (
                                            <div
                                                key={ev.id}
                                                className="flex items-center justify-between rounded-lg border p-4"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <FileCheck className="h-6 w-6 text-status-success" />
                                                    <div>
                                                        <p className="font-medium">
                                                            {ev.title}
                                                        </p>
                                                        <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs capitalize"
                                                            >
                                                                {
                                                                    ev.evidence_type
                                                                }
                                                            </Badge>
                                                            <span>
                                                                by{' '}
                                                                {
                                                                    ev
                                                                        .uploaded_by
                                                                        ?.name
                                                                }
                                                            </span>
                                                            <span>
                                                                {ev.uploaded_at}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                {ev.valid_until && (
                                                    <Badge variant="outline">
                                                        Valid until{' '}
                                                        {ev.valid_until}
                                                    </Badge>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="py-8 text-center text-muted-foreground">
                                        <Upload className="mx-auto mb-2 h-12 w-12 opacity-50" />
                                        <p>No evidence uploaded yet</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Reminders */}
                        {reminderItems.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Scheduled Reminders</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {reminderItems.map((reminder) => (
                                            <div
                                                key={reminder.id}
                                                className="flex items-center justify-between rounded-lg border p-3"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Clock className="h-4 w-4 text-muted-foreground" />
                                                    <span className="text-sm">
                                                        {
                                                            reminder.days_before_due
                                                        }{' '}
                                                        days before due
                                                    </span>
                                                </div>
                                                <Badge
                                                    className={cn(
                                                        reminder.status ===
                                                            'sent' &&
                                                            'bg-status-success-bg text-status-success',
                                                        reminder.status ===
                                                            'pending' &&
                                                            'bg-muted text-foreground',
                                                    )}
                                                >
                                                    {reminder.status}
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-6">
                        {/* Status Card */}
                        <Card
                            className={cn(
                                obligation.status === 'overdue' &&
                                    'border-status-critical/30 bg-status-critical-bg',
                                obligation.status === 'due_soon' &&
                                    'border-status-warning/30 bg-status-warning-bg',
                                obligation.status === 'complete' &&
                                    'border-status-success/30 bg-status-success-bg',
                            )}
                        >
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    {obligation.status === 'complete' ? (
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                    ) : obligation.status === 'overdue' ? (
                                        <AlertTriangle className="h-5 w-5 text-status-critical" />
                                    ) : (
                                        <Clock className="h-5 w-5 text-status-warning" />
                                    )}
                                    Status
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {obligation.status === 'complete' ? (
                                    <div className="space-y-2">
                                        <div>
                                            <p className="font-medium text-status-success">
                                                Completed
                                            </p>
                                            <p className="text-sm text-status-success">
                                                {obligation.completed_at} by{' '}
                                                {obligation.completed_by?.name || 'Authorized Staff'}
                                            </p>
                                        </div>
                                        {obligation.completion_notes && (
                                            <div className="rounded bg-background/80 p-2 text-xs">
                                                <p className="font-semibold text-muted-foreground">
                                                    Completion Notes:
                                                </p>
                                                <p className="text-foreground whitespace-pre-wrap">
                                                    {obligation.completion_notes}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div>
                                        <p className="text-2xl font-bold">
                                            {daysRemaining() < 0 ? (
                                                <span className="text-status-critical">
                                                    {Math.abs(daysRemaining())}{' '}
                                                    days overdue
                                                </span>
                                            ) : (
                                                <span
                                                    className={
                                                        daysRemaining() <= 7
                                                            ? 'text-status-warning'
                                                            : 'text-foreground'
                                                    }
                                                >
                                                    {daysRemaining()} days
                                                    remaining
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Due: {obligation.due_date}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Owner
                                    </p>
                                    <p className="flex items-center gap-2 font-medium">
                                        <User className="h-4 w-4" />
                                        {obligation.owner?.name ||
                                            'Not assigned'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Frequency
                                    </p>
                                    <p className="font-medium capitalize">
                                        {obligation.frequency}
                                    </p>
                                </div>
                                {obligation.parent_obligation && (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Prior Cycle
                                        </p>
                                        <a
                                            href={`/governance/compliance/${obligation.parent_obligation.id}`}
                                            className="text-sm font-medium text-primary hover:underline"
                                        >
                                            {obligation.parent_obligation.obligation_title} ({obligation.parent_obligation.due_date})
                                        </a>
                                    </div>
                                )}
                                {obligation.recurrences && obligation.recurrences.length > 0 && (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Next Cycle
                                        </p>
                                        <div className="space-y-1 mt-0.5">
                                            {obligation.recurrences.map((rec) => (
                                                <a
                                                    key={rec.id}
                                                    href={`/governance/compliance/${rec.id}`}
                                                    className="block text-sm font-medium text-primary hover:underline"
                                                >
                                                    Due {rec.due_date} ({rec.status})
                                                </a>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                {obligation.next_due_date && (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Next Due Date
                                        </p>
                                        <p className="font-medium">
                                            {obligation.next_due_date}
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Evidence
                                    </p>
                                    <p className="font-medium">
                                        {obligation.evidence_provided ? (
                                            <span className="flex items-center gap-1 text-status-success">
                                                <CheckCircle className="h-4 w-4" />
                                                Provided
                                            </span>
                                        ) : obligation.evidence_required ? (
                                            <span className="text-status-critical">
                                                Required - Not provided
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not required
                                            </span>
                                        )}
                                    </p>
                                </div>
                                {obligation.sign_off_required && (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Sign-off
                                        </p>
                                        {obligation.signed_off_at ? (
                                            <p className="text-sm text-status-success">
                                                Signed by{' '}
                                                {obligation.signed_off_by?.name}{' '}
                                                on {obligation.signed_off_at}
                                            </p>
                                        ) : (
                                            <p className="text-sm text-status-warning">
                                                Pending sign-off
                                            </p>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
