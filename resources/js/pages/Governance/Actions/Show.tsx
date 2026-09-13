import { PageHero, PageLayout } from '@/components/page';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import {
    block as blockAction,
    complete as completeAction,
    escalate as escalateAction,
    progress as progressAction,
    unblock as unblockAction,
} from '@/routes/governance/actions';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Check,
    CheckCircle,
    ClipboardList,
    Clock,
    Copy,
    ExternalLink,
    FileText,
    Flag,
    Lock,
    PauseCircle,
    Play,
    User,
} from 'lucide-react';
import React, { useState } from 'react';

interface UserRef {
    id: number;
    name: string;
    email?: string | null;
}

interface ActionItem {
    id: number;
    action_reference: string;
    title?: string | null;
    description: string;
    due_date: string;
    status: string;
    priority: string;
    source_type: string | null;
    source_id: number | null;
    follow_up_key?: string | null;
    evidence_required: boolean;
    evidence_attachments?: string[] | null;
    completion_notes?: string | null;
    completion_receipt?: string | null;
    completed_at?: string | null;
    progress_pct?: number;
    progress_notes?: string | null;
    version_number?: number;
    blocked_at?: string | null;
    blocked_reason?: string | null;
    escalated_at?: string | null;
    escalation_reason?: string | null;
    assigned_to?: UserRef | null;
    completed_by?: UserRef | null;
    created_by?: UserRef | null;
    escalated_by?: UserRef | null;
}

interface SourceDetails {
    type: 'resolution' | 'meeting';
    id: number;
    reference?: string;
    title?: string;
    status?: string;
    outcome?: string;
    scheduled_at?: string;
    url?: string;
    is_restricted?: boolean;
}

interface Props extends PageProps {
    action: ActionItem;
    source_details?: SourceDetails | null;
    assignees: UserRef[];
    can_update: boolean;
}

export default function ActionItemShow({ auth, action, source_details, assignees, can_update }: Props) {
    const [isProgressOpen, setIsProgressOpen] = useState(false);
    const [isBlockOpen, setIsBlockOpen] = useState(false);
    const [isEscalateOpen, setIsEscalateOpen] = useState(false);
    const [isReassignOpen, setIsReassignOpen] = useState(false);
    const [isCompleteOpen, setIsCompleteOpen] = useState(false);
    const [copiedReceipt, setCopiedReceipt] = useState(false);

    // Form: Progress Update
    const {
        data: progressData,
        setData: setProgressData,
        post: postProgress,
        processing: progressProcessing,
        errors: progressErrors,
    } = useForm({
        progress_pct: action.progress_pct ?? 0,
        progress_notes: action.progress_notes ?? '',
        expected_version: action.version_number ?? 1,
    });

    // Form: Block
    const {
        data: blockData,
        setData: setBlockData,
        post: postBlock,
        processing: blockProcessing,
        errors: blockErrors,
    } = useForm({
        blocked_reason: '',
        expected_version: action.version_number ?? 1,
    });

    // Form: Escalate
    const {
        data: escalateData,
        setData: setEscalateData,
        post: postEscalate,
        processing: escalateProcessing,
        errors: escalateErrors,
    } = useForm({
        escalation_reason: '',
        expected_version: action.version_number ?? 1,
    });

    // Form: Reassign
    const {
        data: reassignData,
        setData: setReassignData,
    } = useForm({
        assigned_to: action.assigned_to?.id ? String(action.assigned_to.id) : '',
        expected_version: action.version_number ?? 1,
    });

    // Form: Complete
    const {
        data: completeData,
        setData: setCompleteData,
        post: postComplete,
        processing: completeProcessing,
        errors: completeErrors,
    } = useForm({
        completion_notes: '',
        evidence_files: [] as string[],
        expected_version: action.version_number ?? 1,
    });

    const [newEvidenceFile, setNewEvidenceFile] = useState('');

    const isCompleted = action.status === 'complete';
    const isBlocked = action.status === 'blocked';

    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getPriorityColor = (priority: string) => {
        return (
            {
                low: 'bg-muted text-foreground',
                medium: 'bg-status-info-bg text-status-info',
                high: 'bg-status-warning-bg text-status-warning',
                critical: 'bg-status-critical-bg text-status-critical',
            }[priority] || 'bg-muted text-foreground'
        );
    };

    const formatDate = (dateString: string | null | undefined) => {
        if (!dateString) return 'Not set';
        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) return dateString;
        return date.toLocaleDateString('en-NZ', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const handleCopyReceipt = () => {
        if (action.completion_receipt) {
            navigator.clipboard.writeText(action.completion_receipt);
            setCopiedReceipt(true);
            setTimeout(() => setCopiedReceipt(false), 2000);
        }
    };

    const handleProgressSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postProgress(progressAction.url({ action: action.id }), {
            onSuccess: () => setIsProgressOpen(false),
        });
    };

    const handleBlockSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postBlock(blockAction.url({ action: action.id }), {
            onSuccess: () => setIsBlockOpen(false),
        });
    };

    const handleUnblock = () => {
        router.post(unblockAction.url({ action: action.id }), {
            expected_version: action.version_number ?? 1,
        });
    };

    const handleEscalateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postEscalate(escalateAction.url({ action: action.id }), {
            onSuccess: () => setIsEscalateOpen(false),
        });
    };

    const handleReassignSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/governance/actions/${action.id}/reassign`, {
            assigned_to: reassignData.assigned_to,
            expected_version: action.version_number ?? 1,
        }, {
            onSuccess: () => setIsReassignOpen(false),
        });
    };

    const handleCompleteSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postComplete(completeAction.url({ action: action.id }), {
            onSuccess: () => setIsCompleteOpen(false),
        });
    };

    const addEvidenceFile = () => {
        if (newEvidenceFile.trim()) {
            setCompleteData('evidence_files', [...completeData.evidence_files, newEvidenceFile.trim()]);
            setNewEvidenceFile('');
        }
    };

    const removeEvidenceFile = (idx: number) => {
        setCompleteData('evidence_files', completeData.evidence_files.filter((_, i) => i !== idx));
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Actions', href: '/governance/actions' },
                { title: action.action_reference, href: `/governance/actions/${action.id}` },
            ]}
        >
            <Head title={`Action ${action.action_reference}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/actions"
                        icon={ClipboardList}
                        title={
                            <span className="flex flex-wrap items-center gap-3" dusk="action-heading">
                                {action.action_reference}
                                <Badge className={cn('capitalize', getStatusColor(action.status))}>
                                    {isCompleted ? 'Completed' : action.status.replace('_', ' ')}
                                </Badge>
                                <Badge className={cn('capitalize', getPriorityColor(action.priority))}>
                                    {action.priority}
                                </Badge>
                                {action.version_number && action.version_number > 1 && (
                                    <span className="text-xs text-muted-foreground font-mono">
                                        v{action.version_number}
                                    </span>
                                )}
                            </span>
                        }
                        description={action.title || action.description}
                        stats={[
                            { label: 'Status', value: isCompleted ? 'Completed' : action.status.replace('_', ' ') },
                            { label: 'Priority', value: action.priority },
                            { label: 'Due Date', value: formatDate(action.due_date) },
                            { label: 'Assignee', value: action.assigned_to?.name ?? 'Unassigned' },
                        ]}
                    />
                }
            >
                {/* Blocked or Escalated Warning Banners */}
                {isBlocked && (
                    <Alert className="mb-6 border-status-warning/40 bg-status-warning-bg/20 text-status-warning">
                        <AlertCircle className="h-5 w-5 text-status-warning" />
                        <AlertTitle className="font-semibold text-status-warning">
                            Action Blocked
                        </AlertTitle>
                        <AlertDescription className="text-sm mt-1 text-foreground">
                            {action.blocked_reason}
                            {action.blocked_at && (
                                <span className="block text-xs text-muted-foreground mt-1">
                                    Flagged on {formatDate(action.blocked_at)}
                                </span>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {action.escalated_at && (
                    <Alert className="mb-6 border-status-critical/40 bg-status-critical-bg/20 text-status-critical">
                        <Flag className="h-5 w-5 text-status-critical" />
                        <AlertTitle className="font-semibold text-status-critical">
                            Escalated to Governance
                        </AlertTitle>
                        <AlertDescription className="text-sm mt-1 text-foreground">
                            {action.escalation_reason}
                            <span className="block text-xs text-muted-foreground mt-1">
                                Escalated by {action.escalated_by?.name ?? 'System'} on {formatDate(action.escalated_at)}
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Main 2-column Grid */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Left 2 Cols: Details, Source, Evidence */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Action Details Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base font-semibold">Deliverable Scope & Context</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm whitespace-pre-line text-foreground leading-relaxed">
                                    {action.description}
                                </p>

                                <div className="grid grid-cols-2 gap-4 pt-4 border-t text-sm">
                                    <div>
                                        <span className="text-xs text-muted-foreground block">Created By</span>
                                        <span className="font-medium">{action.created_by?.name ?? 'Governance Lead'}</span>
                                    </div>
                                    <div>
                                        <span className="text-xs text-muted-foreground block">Evidence Requirement</span>
                                        <Badge variant={action.evidence_required ? 'destructive' : 'secondary'} className="text-xs">
                                            {action.evidence_required ? 'Mandatory Documentation Required' : 'Standard Verification'}
                                        </Badge>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Originating Source Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base font-semibold flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-muted-foreground" />
                                    Originating Source
                                </CardTitle>
                                <CardDescription>
                                    The canonical governance paper or meeting that authored this accountable follow-up.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {source_details ? (
                                    source_details.is_restricted ? (
                                        <div className="flex items-center gap-3 p-4 rounded-lg bg-muted/40 border text-muted-foreground text-sm">
                                            <Lock className="h-4 w-4 text-muted-foreground shrink-0" />
                                            <span>
                                                Restricted governance source record. Detailed motion and minutes are confidential to the authorised electorate.
                                            </span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-between p-4 rounded-lg border bg-muted/20">
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-xs font-semibold px-2 py-0.5 rounded bg-muted text-foreground">
                                                        {source_details.type === 'resolution' ? (source_details.reference ?? 'Resolution') : 'Meeting'}
                                                    </span>
                                                    {source_details.outcome && (
                                                        <Badge variant="outline" className="text-xs capitalize">
                                                            {source_details.outcome}
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="font-medium text-sm text-foreground">
                                                    {source_details.title}
                                                </p>
                                                {source_details.scheduled_at && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Scheduled {formatDate(source_details.scheduled_at)}
                                                    </p>
                                                )}
                                            </div>

                                            {source_details.url && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={source_details.url} className="flex items-center gap-1.5">
                                                        <span>View Source</span>
                                                        <ExternalLink className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    )
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {action.source_type
                                            ? `Origin: ${action.source_type.replace(/.*\\/, '')} #${action.source_id}`
                                            : 'Stand-alone action item; not bound to a meeting or resolution.'}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Completion / Durable Receipt Card */}
                        <Card className={cn(isCompleted && 'border-status-success/30 bg-status-success-bg/5')}>
                            <CardHeader>
                                <CardTitle className="text-base font-semibold flex items-center justify-between">
                                    <span className="flex items-center gap-2">
                                        <CheckCircle className={cn('h-4 w-4', isCompleted ? 'text-status-success' : 'text-muted-foreground')} />
                                        Completion & Verification
                                    </span>
                                    {isCompleted && (
                                        <Badge className="bg-status-success text-white">
                                            Completed
                                        </Badge>
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    {isCompleted
                                        ? 'Audited sign-off with completion notes and durable verification receipt.'
                                        : 'Accountable follow-through requires substantive completion notes and supporting evidence.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {isCompleted ? (
                                    <div className="space-y-4">
                                        {action.completion_receipt && (
                                            <div className="flex items-center justify-between rounded-lg border border-status-success/30 bg-background p-3 text-xs">
                                                <div>
                                                    <span className="text-muted-foreground block">Verification Receipt</span>
                                                    <span className="font-mono font-bold text-foreground">
                                                        {action.completion_receipt}
                                                    </span>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={handleCopyReceipt}
                                                    className="h-8 text-xs"
                                                >
                                                    {copiedReceipt ? (
                                                        <Check className="mr-1 h-3.5 w-3.5 text-status-success" />
                                                    ) : (
                                                        <Copy className="mr-1 h-3.5 w-3.5" />
                                                    )}
                                                    {copiedReceipt ? 'Copied' : 'Copy'}
                                                </Button>
                                            </div>
                                        )}

                                        <div className="grid grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <span className="text-xs text-muted-foreground block">Completed At</span>
                                                <span className="font-medium">{formatDate(action.completed_at)}</span>
                                            </div>
                                            <div>
                                                <span className="text-xs text-muted-foreground block">Completed By</span>
                                                <span className="font-medium">{action.completed_by?.name ?? 'Assigned Owner'}</span>
                                            </div>
                                        </div>

                                        {action.completion_notes && (
                                            <div className="rounded-lg bg-muted/40 p-3 text-sm">
                                                <span className="text-xs font-semibold text-muted-foreground block mb-1">
                                                    Completion Notes
                                                </span>
                                                <p className="text-foreground whitespace-pre-line">{action.completion_notes}</p>
                                            </div>
                                        )}

                                        {action.evidence_attachments && action.evidence_attachments.length > 0 && (
                                            <div className="space-y-1.5 pt-2">
                                                <span className="text-xs font-semibold text-muted-foreground block">
                                                    Supporting Evidence Files ({action.evidence_attachments.length})
                                                </span>
                                                <ul className="divide-y rounded-md border bg-background text-xs">
                                                    {action.evidence_attachments.map((file, i) => (
                                                        <li key={i} className="flex items-center gap-2 p-2.5">
                                                            <FileText className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                            <span className="font-mono truncate">{file}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <p className="text-sm text-muted-foreground">
                                            Setting progress to 100% does not close an action item. When deliverables are complete, submit final sign-off notes and documentation below.
                                        </p>
                                        {can_update && (
                                            <Button
                                                className="w-full sm:w-auto"
                                                onClick={() => setIsCompleteOpen(true)}
                                            >
                                                <CheckCircle className="mr-1.5 h-4 w-4" />
                                                Complete Action Item
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right 1 Col: Status, Progress, Controls */}
                    <div className="space-y-6">
                        {/* Progress Meter Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base font-semibold">Progress Status</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">Deliverable Progress</span>
                                        <span className="font-bold">{action.progress_pct ?? 0}%</span>
                                    </div>
                                    <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className={cn(
                                                'h-full transition-all',
                                                (action.progress_pct ?? 0) >= 100 ? 'bg-status-success' : 'bg-primary',
                                            )}
                                            style={{ width: `${action.progress_pct ?? 0}%` }}
                                        />
                                    </div>
                                </div>

                                {action.progress_notes && (
                                    <div className="rounded-md bg-muted/40 p-2.5 text-xs text-muted-foreground">
                                        <span className="font-semibold text-foreground block mb-0.5">Latest Update:</span>
                                        {action.progress_notes}
                                    </div>
                                )}

                                {can_update && !isCompleted && (
                                    <div className="space-y-2 pt-2">
                                        <Button
                                            variant="default"
                                            className="w-full justify-center"
                                            onClick={() => setIsProgressOpen(true)}
                                        >
                                            Update Progress
                                        </Button>

                                        {isBlocked ? (
                                            <Button
                                                variant="outline"
                                                className="w-full justify-center text-status-success border-status-success/30 hover:bg-status-success-bg/10"
                                                onClick={handleUnblock}
                                            >
                                                <Play className="mr-1.5 h-4 w-4" />
                                                Remove Blocker
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                className="w-full justify-center text-status-warning border-status-warning/30 hover:bg-status-warning-bg/10"
                                                onClick={() => setIsBlockOpen(true)}
                                            >
                                                <PauseCircle className="mr-1.5 h-4 w-4" />
                                                Mark as Blocked
                                            </Button>
                                        )}

                                        <Button
                                            variant="outline"
                                            className="w-full justify-center text-status-critical border-status-critical/30 hover:bg-status-critical-bg/10"
                                            onClick={() => setIsEscalateOpen(true)}
                                        >
                                            <Flag className="mr-1.5 h-4 w-4" />
                                            Escalate
                                        </Button>

                                        <Button
                                            variant="ghost"
                                            className="w-full justify-center text-xs"
                                            onClick={() => setIsReassignOpen(true)}
                                        >
                                            <User className="mr-1.5 h-3.5 w-3.5" />
                                            Reassign Owner
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Assignee & Dates Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base font-semibold">Assignment & Timing</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <span className="text-xs text-muted-foreground block">Assigned Owner</span>
                                    <div className="flex items-center gap-2 mt-1">
                                        <User className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-semibold">{action.assigned_to?.name ?? 'Unassigned'}</span>
                                    </div>
                                    {action.assigned_to?.email && (
                                        <span className="text-xs text-muted-foreground block pl-6">
                                            {action.assigned_to.email}
                                        </span>
                                    )}
                                </div>

                                <div className="pt-2 border-t">
                                    <span className="text-xs text-muted-foreground block">Target Completion Date</span>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Clock className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">{formatDate(action.due_date)}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>

            {/* Dialog: Update Progress */}
            <Dialog open={isProgressOpen} onOpenChange={setIsProgressOpen}>
                <DialogContent className="sm:max-w-[450px]">
                    <DialogHeader>
                        <DialogTitle>Update Progress</DialogTitle>
                        <DialogDescription>
                            Record progress percentage and milestone details. 100% progress alone does not close the item.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleProgressSubmit} className="space-y-4 py-2">
                        <div className="space-y-2">
                            <div className="flex justify-between text-sm">
                                <Label htmlFor="progress-range">Completion Percentage</Label>
                                <span className="font-bold">{progressData.progress_pct}%</span>
                            </div>
                            <input
                                id="progress-range"
                                type="range"
                                min={0}
                                max={100}
                                step={5}
                                className="w-full accent-primary"
                                value={progressData.progress_pct}
                                onChange={(e) => setProgressData('progress_pct', Number(e.target.value))}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="progress-notes">Progress Notes</Label>
                            <Textarea
                                id="progress-notes"
                                placeholder="Summary of what was achieved or next steps..."
                                rows={3}
                                value={progressData.progress_notes}
                                onChange={(e) => setProgressData('progress_notes', e.target.value)}
                            />
                            {progressErrors.progress_notes && (
                                <p className="text-xs text-status-critical">{progressErrors.progress_notes}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsProgressOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={progressProcessing}>
                                Save Progress
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Mark as Blocked */}
            <Dialog open={isBlockOpen} onOpenChange={setIsBlockOpen}>
                <DialogContent className="sm:max-w-[450px]">
                    <DialogHeader>
                        <DialogTitle>Mark Action as Blocked</DialogTitle>
                        <DialogDescription>
                            Explain the dependency, missing information, or roadblock impeding progress.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleBlockSubmit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="block-reason">Blocker Reason <span className="text-status-critical">*</span></Label>
                            <Textarea
                                id="block-reason"
                                placeholder="State specifically what is preventing completion..."
                                rows={3}
                                value={blockData.blocked_reason}
                                onChange={(e) => setBlockData('blocked_reason', e.target.value)}
                                required
                            />
                            {blockErrors.blocked_reason && (
                                <p className="text-xs text-status-critical">{blockErrors.blocked_reason}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsBlockOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="destructive" disabled={blockProcessing || !blockData.blocked_reason}>
                                Confirm Blocker
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Escalate */}
            <Dialog open={isEscalateOpen} onOpenChange={setIsEscalateOpen}>
                <DialogContent className="sm:max-w-[450px]">
                    <DialogHeader>
                        <DialogTitle>Escalate to Governance Authority</DialogTitle>
                        <DialogDescription>
                            Escalate this action item to alert the board chair and secretariat for intervention.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleEscalateSubmit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="escalate-reason">Escalation Reason <span className="text-status-critical">*</span></Label>
                            <Textarea
                                id="escalate-reason"
                                placeholder="Explain why governance intervention is required..."
                                rows={3}
                                value={escalateData.escalation_reason}
                                onChange={(e) => setEscalateData('escalation_reason', e.target.value)}
                                required
                            />
                            {escalateErrors.escalation_reason && (
                                <p className="text-xs text-status-critical">{escalateErrors.escalation_reason}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsEscalateOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="destructive" disabled={escalateProcessing || !escalateData.escalation_reason}>
                                Escalate Action
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Reassign */}
            <Dialog open={isReassignOpen} onOpenChange={setIsReassignOpen}>
                <DialogContent className="sm:max-w-[450px]">
                    <DialogHeader>
                        <DialogTitle>Reassign Action Item</DialogTitle>
                        <DialogDescription>
                            Transfer accountability to another approved governance contributor.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleReassignSubmit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="reassign-user">Select New Assignee <span className="text-status-critical">*</span></Label>
                            <select
                                id="reassign-user"
                                className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                value={reassignData.assigned_to}
                                onChange={(e) => setReassignData('assigned_to', e.target.value)}
                                required
                            >
                                {assignees.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} ({u.email})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsReassignOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={reassignData.assigned_to === ''}>
                                Reassign
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Complete Action Item */}
            <Dialog open={isCompleteOpen} onOpenChange={setIsCompleteOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>Complete Action Item</DialogTitle>
                        <DialogDescription>
                            Confirm completion by providing formal notes and attaching required verification evidence.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleCompleteSubmit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="complete-notes">Completion Notes <span className="text-status-critical">*</span></Label>
                            <Textarea
                                id="complete-notes"
                                placeholder="Detail the concrete work completed, approvals obtained, or outcomes realised..."
                                rows={3}
                                value={completeData.completion_notes}
                                onChange={(e) => setCompleteData('completion_notes', e.target.value)}
                                required
                            />
                            {completeErrors.completion_notes && (
                                <p className="text-xs text-status-critical">{completeErrors.completion_notes}</p>
                            )}
                        </div>

                        {action.evidence_required && (
                            <div className="space-y-2 rounded-lg border border-status-warning/30 bg-status-warning-bg/10 p-3">
                                <Label className="text-xs font-semibold text-status-warning flex items-center gap-1.5">
                                    <AlertTriangle className="h-3.5 w-3.5" />
                                    Mandatory Verification Evidence Required
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Attach links, documents, or reference identifiers validating the completed deliverable.
                                </p>

                                <div className="flex gap-2 pt-1">
                                    <Input
                                        placeholder="E.g. signed-policy-v2.pdf, Audit-Report-2026.docx"
                                        value={newEvidenceFile}
                                        onChange={(e) => setNewEvidenceFile(e.target.value)}
                                        className="h-8 text-xs"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        className="h-8 text-xs"
                                        onClick={addEvidenceFile}
                                    >
                                        Add
                                    </Button>
                                </div>

                                {completeData.evidence_files.length > 0 && (
                                    <ul className="space-y-1 pt-1">
                                        {completeData.evidence_files.map((file, i) => (
                                            <li key={i} className="flex items-center justify-between text-xs bg-background p-1.5 rounded border">
                                                <span className="font-mono truncate">{file}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeEvidenceFile(i)}
                                                    className="text-status-critical hover:underline text-[11px]"
                                                >
                                                    Remove
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIsCompleteOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    completeProcessing ||
                                    !completeData.completion_notes.trim() ||
                                    (action.evidence_required && completeData.evidence_files.length === 0 && (!action.evidence_attachments || action.evidence_attachments.length === 0))
                                }
                            >
                                Confirm & Complete
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
