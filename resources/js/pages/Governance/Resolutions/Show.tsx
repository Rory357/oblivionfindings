import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { PageHeader, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ResolutionWizardDialog } from './_dialogs';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    close as closeResolution,
    open as openResolution,
    vote as voteResolution,
} from '@/routes/governance/resolutions';
import { declare as declareConflictRoute } from '@/routes/governance/resolutions/conflict';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    CheckCircle2,
    DollarSign,
    Edit3,
    Gavel,
    Lock,
    MinusCircle,
    Paperclip,
    Plus,
    Scale,
    ShieldAlert,
    Trash2,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface Vote {
    id: number;
    board_member: { user: { name: string } };
    vote: string;
    voting_method?: string;
    conflict_declared: boolean;
    voted_at: string;
}

interface Conflict {
    id: number;
    board_member: { user: { name: string } };
    declaration_type: string;
    declaration_text?: string;
    withdrew_from_voting: boolean;
    declared_at?: string;
}

type ResultMember =
    | string
    | { user?: { name?: string | null } | null }
    | null
    | undefined;

interface ResultVote {
    board_member: ResultMember;
    vote: string;
    conflict_declared: boolean;
    voted_at: string;
}

interface ResultConflict {
    board_member: ResultMember;
    type: string;
    description: string;
    withdrew: boolean;
}

interface OptionItem {
    label: string;
    description?: string;
    benefits?: string;
    drawbacks?: string;
}

interface Resolution {
    id: number;
    resolution_reference: string;
    title: string;
    exact_motion?: string | null;
    purpose?: string | null;
    decision_type?: string | null;
    context: string;
    options: OptionItem[];
    single_option_reason?: string | null;
    recommendation: string | null;
    cost_impact?: any;
    risk_impact?: any;
    service_user_implications?: string | null;
    risk_equity_implications?: string | null;
    voting_threshold: string;
    status: string;
    version_number?: number;
    deadline: string | null;
    outcome: string | null;
    outcome_notes?: string | null;
    vote_summary: {
        for: number;
        against: number;
        abstain: number;
        total_votes: number;
    } | null;
    proposed_by: { name: string };
    meeting: { id: number; title: string; scheduled_at?: string } | null;
    committee?: { id: number; name: string } | null;
    votes: Vote[];
    conflict_declarations: Conflict[];
    follow_up_actions?: Array<{
        title: string;
        assignee_name?: string;
        due_date?: string;
    }>;
    paper_snapshot?: any;
}

interface Props extends PageProps {
    resolution: Resolution;
    results: {
        summary: { for: number; against: number; abstain: number };
        percentages: { for: number; against: number };
        outcome: string;
        quorum_met: boolean;
        quorum_details?: any;
        individual_votes: ResultVote[];
        conflicts: ResultConflict[];
        is_frozen?: boolean;
        decision_snapshot?: any;
    } | null;
    my_vote: Vote | null;
    my_conflict?: Conflict | null;
    can_vote: boolean;
    can_manage?: boolean;
    quorum: { present: number; required: number; met: boolean; total_eligible?: number; percentage_present?: number; resolution_mode?: string } | null;
    attachments: GovernanceAttachment[];
    paper_snapshot?: any;
    validation_errors?: string[];
    meetings?: Array<{ id: number; title: string; scheduled_at: string }>;
    committees?: Array<{ id: number; name: string }>;
}

export default function ResolutionShow({
    auth,
    resolution,
    results,
    my_vote,
    my_conflict,
    can_vote,
    can_manage,
    quorum,
    attachments,
    validation_errors = [],
    meetings = [],
    committees = [],
}: Props) {
    const [selectedVote, setSelectedVote] = useState<string>('');
    const [conflictNote, setConflictNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [declaringConflict, setDeclaringConflict] = useState(false);
    const [conflictDialogOpen, setConflictDialogOpen] = useState(false);
    const [conflictType, setConflictType] = useState<string>('material');
    const [conflictDescription, setConflictDescription] = useState<string>('');
    const [conflictWithdrawVoting, setConflictWithdrawVoting] = useState<boolean>(true);
    const [conflictWithdrawDiscussion, setConflictWithdrawDiscussion] = useState<boolean>(false);
    const [finalNotes, setFinalNotes] = useState('');
    const [finalizing, setFinalizing] = useState(false);
    const [openingVoting, setOpeningVoting] = useState(false);
    const [openVotingError, setOpenVotingError] = useState<string | null>(null);

    // Edit modal state
    const [editDialogOpen, setEditDialogOpen] = useState(false);
    const [savingPaper, setSavingPaper] = useState(false);
    const [editError, setEditError] = useState<string | null>(null);
    const [editTitle, setEditTitle] = useState(resolution.title || '');
    const [editExactMotion, setEditExactMotion] = useState(resolution.exact_motion || '');
    const [editPurpose, setEditPurpose] = useState(resolution.purpose || 'decision');
    const [editDecisionType, setEditDecisionType] = useState(resolution.decision_type || 'strategic');
    const [editContext, setEditContext] = useState(resolution.context || '');
    const [editOptions, setEditOptions] = useState<OptionItem[]>(
        resolution.options?.length > 0
            ? resolution.options
            : [
                  { label: 'Option 1: Proposed action', description: '', benefits: '', drawbacks: '' },
                  { label: 'Option 2: Status quo / Alternative', description: '', benefits: '', drawbacks: '' },
              ]
    );
    const [editSingleOptionReason, setEditSingleOptionReason] = useState(resolution.single_option_reason || '');
    const [editRecommendation, setEditRecommendation] = useState(resolution.recommendation || '');
    const [editHasCost, setEditHasCost] = useState<boolean>(!!resolution.cost_impact?.has_cost);
    const [editCostAmount, setEditCostAmount] = useState<string>(resolution.cost_impact?.amount || '');
    const [editCostCurrency, setEditCostCurrency] = useState<string>(resolution.cost_impact?.currency || 'NZD');
    const [editCostSource, setEditCostSource] = useState<string>(resolution.cost_impact?.budget_source || '');
    const [editServiceUser, setEditServiceUser] = useState(resolution.service_user_implications || '');
    const [editRiskEquity, setEditRiskEquity] = useState(resolution.risk_equity_implications || '');

    const addEditOption = () => {
        setEditOptions([
            ...editOptions,
            { label: `Option ${editOptions.length + 1}`, description: '', benefits: '', drawbacks: '' },
        ]);
    };

    const removeEditOption = (index: number) => {
        if (editOptions.length <= 1) return;
        setEditOptions(editOptions.filter((_, idx) => idx !== index));
    };

    const updateEditOption = (index: number, field: keyof OptionItem, value: string) => {
        const updated = [...editOptions];
        updated[index] = { ...updated[index], [field]: value };
        setEditOptions(updated);
    };

    const permissions = auth?.can as
        | { governance?: { resolutions?: { manage?: boolean } } }
        | undefined;
    const canManage = can_manage ?? permissions?.governance?.resolutions?.manage;

    // Prefer frozen paper snapshot when open, closed, or finalized to guarantee reading integrity
    const displayData = resolution.paper_snapshot ?? {
        exact_motion: resolution.exact_motion,
        purpose: resolution.purpose,
        decision_type: resolution.decision_type,
        context: resolution.context,
        options: resolution.options ?? [],
        single_option_reason: resolution.single_option_reason,
        recommendation: resolution.recommendation,
        cost_impact: resolution.cost_impact,
        risk_impact: resolution.risk_impact,
        service_user_implications: resolution.service_user_implications,
        risk_equity_implications: resolution.risk_equity_implications,
        version_number: resolution.version_number,
    };

    const options = (displayData.options ?? []) as OptionItem[];
    const isDraft = resolution.status === 'draft';
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(
        resolution.status,
    );
    const isFinalized = ['implemented', 'archived'].includes(resolution.status);

    const resolveMemberName = (member: ResultMember) => {
        if (!member) return 'Unknown';
        if (typeof member === 'string') return member;
        return member.user?.name ?? 'Unknown';
    };

    const openEditModal = () => {
        setEditTitle(resolution.title || '');
        setEditExactMotion(resolution.exact_motion || '');
        setEditPurpose(resolution.purpose || 'decision');
        setEditDecisionType(resolution.decision_type || 'strategic');
        setEditContext(resolution.context || '');
        setEditOptions(
            resolution.options?.length > 0
                ? resolution.options
                : [
                      { label: 'Option 1: Proposed action', description: '', benefits: '', drawbacks: '' },
                      { label: 'Option 2: Status quo / Alternative', description: '', benefits: '', drawbacks: '' },
                  ]
        );
        setEditSingleOptionReason(resolution.single_option_reason || '');
        setEditRecommendation(resolution.recommendation || '');
        setEditHasCost(!!resolution.cost_impact?.has_cost);
        setEditCostAmount(resolution.cost_impact?.amount || '');
        setEditCostCurrency(resolution.cost_impact?.currency || 'NZD');
        setEditCostSource(resolution.cost_impact?.budget_source || '');
        setEditServiceUser(resolution.service_user_implications || '');
        setEditRiskEquity(resolution.risk_equity_implications || '');
        setEditError(null);
        setEditDialogOpen(true);
    };

    const handleUpdatePaper = async (e: React.FormEvent) => {
        e.preventDefault();
        setSavingPaper(true);
        setEditError(null);
        try {
            const costImpact = editHasCost
                ? {
                      has_cost: true,
                      amount: editCostAmount,
                      currency: editCostCurrency,
                      budget_source: editCostSource,
                  }
                : {
                      has_cost: false,
                      note: 'Explicitly confirmed: No direct financial implications.',
                  };

            await axios.put(`/governance/resolutions/${resolution.id}`, {
                title: editTitle,
                exact_motion: editExactMotion,
                purpose: editPurpose,
                decision_type: editDecisionType,
                context: editContext,
                options: editOptions,
                single_option_reason: editSingleOptionReason,
                recommendation: editRecommendation,
                cost_impact: costImpact,
                service_user_implications: editServiceUser,
                risk_equity_implications: editRiskEquity,
                expected_version: resolution.version_number ?? 1,
            });
            setEditDialogOpen(false);
            router.reload();
        } catch (err: any) {
            if (err.response?.status === 409) {
                setEditError('Conflict: This paper has been modified by another user. Please reload the page.');
            } else {
                setEditError(err.response?.data?.message || 'Failed to update decision paper.');
            }
        } finally {
            setSavingPaper(false);
        }
    };

    const submitVote = async () => {
        if (!selectedVote) return;
        setSubmitting(true);
        try {
            await axios.post(
                voteResolution.url({ resolution: resolution.id }),
                {
                    vote: selectedVote,
                    conflict_note: conflictNote || undefined,
                },
            );
            router.reload();
        } catch (error) {
            console.error('Vote failed:', error);
        } finally {
            setSubmitting(false);
        }
    };

    const handleDeclareConflict = async () => {
        if (!conflictDescription || conflictDescription.trim().length < 20) return;
        setDeclaringConflict(true);
        try {
            await axios.post(
                declareConflictRoute.url({ resolution: resolution.id }),
                {
                    type: conflictType,
                    description: conflictDescription,
                    withdraw_from_voting: conflictWithdrawVoting,
                    withdraw_from_discussion: conflictWithdrawDiscussion,
                },
            );
            setConflictDialogOpen(false);
            router.reload();
        } catch (error) {
            console.error('Conflict declaration failed:', error);
        } finally {
            setDeclaringConflict(false);
        }
    };

    const openVoting = async () => {
        setOpeningVoting(true);
        setOpenVotingError(null);
        try {
            await axios.post(openResolution.url({ resolution: resolution.id }));
            router.reload();
        } catch (error: any) {
            console.error('Failed to open voting:', error);
            setOpenVotingError(
                error.response?.data?.message ||
                    'Failed to open voting. Please ensure all mandatory paper requirements are completed.'
            );
        } finally {
            setOpeningVoting(false);
        }
    };

    const closeVoting = async () => {
        try {
            await axios.post(
                closeResolution.url({ resolution: resolution.id }),
            );
            router.reload();
        } catch (error) {
            console.error('Failed to close voting:', error);
        }
    };

    const finalizeResolution = async (status: 'implemented' | 'archived') => {
        setFinalizing(true);
        try {
            await axios.post(
                `/governance/resolutions/${resolution.id}/finalize`,
                {
                    status,
                    notes: finalNotes || undefined,
                },
            );
            router.reload();
        } catch (error) {
            console.error('Failed to finalize resolution:', error);
        } finally {
            setFinalizing(false);
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
                {
                    title: 'Resolution',
                    href: `/governance/resolutions/${resolution.id}`,
                },
            ]}
        >
            <Head title={resolution.title} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={
                            resolution.meeting
                                ? `/governance/meetings/${resolution.meeting.id}?tab=resolutions&paper=${resolution.id}`
                                : '/governance/resolutions'
                        }
                        icon={Gavel}
                        title={resolution.title}
                        wrapTitle
                        titleChip={
                            <div className="flex items-center gap-1.5">
                                <Badge variant="outline" className="uppercase font-mono text-xs">
                                    v{displayData.version_number ?? resolution.version_number ?? 1}
                                </Badge>
                                <StatusBadge
                                    status={
                                        resolution.status === 'open'
                                            ? 'active'
                                            : resolution.status === 'closed' || resolution.status === 'implemented'
                                              ? 'completed'
                                              : resolution.status === 'draft'
                                                ? 'draft'
                                                : 'neutral'
                                    }
                                    label={resolution.status}
                                />
                                {resolution.outcome && (
                                    <StatusBadge
                                        status={
                                            resolution.outcome === 'carried'
                                                ? 'success'
                                                : resolution.outcome === 'defeated'
                                                  ? 'critical'
                                                  : 'warning'
                                        }
                                        label={
                                            resolution.outcome === 'no_quorum'
                                                ? 'No valid decision — quorum not met'
                                                : resolution.outcome
                                        }
                                    />
                                )}
                            </div>
                        }
                        subline={
                            <span>
                                <span>{resolution.resolution_reference}</span>
                                {resolution.meeting && (
                                    <>
                                        <span> · </span>
                                        <span>Meeting: {resolution.meeting.title}</span>
                                    </>
                                )}
                                {resolution.committee && (
                                    <>
                                        <span> · </span>
                                        <span>Committee: {resolution.committee.name}</span>
                                    </>
                                )}
                            </span>
                        }
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {isDraft && canManage && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={openEditModal}
                                    >
                                        <Edit3 className="mr-1.5 h-3.5 w-3.5" />
                                        Edit Paper
                                    </Button>
                                )}
                            </div>
                        }
                    />
                }
            >
                {/* Frozen Snapshot Indicator (Active or Closed Papers) */}
                {resolution.paper_snapshot && (
                    <div className="mb-6 rounded-lg border border-primary/30 bg-primary/5 p-3.5 flex items-center justify-between gap-3 text-xs text-foreground">
                        <div className="flex items-center gap-2.5">
                            <Lock className="h-4 w-4 text-primary shrink-0" />
                            <span>
                                <strong>Frozen Decision Paper Snapshot (v{displayData.version_number ?? 1}):</strong>{' '}
                                This paper snapshot was frozen upon opening voting. All votes evaluate these exact recorded terms.
                            </span>
                        </div>
                        <Badge variant="outline" className="bg-background text-primary shrink-0">
                            Snapshot Locked
                        </Badge>
                    </div>
                )}

                {/* Draft Publication Readiness Card */}
                {isDraft && (
                    <Card
                        className={`mb-6 border ${
                            validation_errors.length === 0
                                ? 'border-status-success/40 bg-status-success-bg/10'
                                : 'border-status-warning/40 bg-status-warning-bg/10'
                        }`}
                    >
                        <CardHeader className="pb-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    {validation_errors.length === 0 ? (
                                        <CheckCircle2 className="h-5 w-5 text-status-success" />
                                    ) : (
                                        <AlertCircle className="h-5 w-5 text-status-warning" />
                                    )}
                                    <CardTitle className="text-base font-semibold">
                                        {validation_errors.length === 0
                                            ? 'Paper Publication Readiness: Complete'
                                            : 'Paper Publication Readiness: Incomplete (Draft Only)'}
                                    </CardTitle>
                                </div>
                                {canManage && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={openEditModal}
                                            className="gap-1.5"
                                        >
                                            <Edit3 className="h-3.5 w-3.5" />
                                            Edit Paper
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={openVoting}
                                            disabled={validation_errors.length > 0 || openingVoting}
                                            className="bg-status-success hover:bg-status-success/90 text-white"
                                        >
                                            {openingVoting ? 'Opening...' : 'Publish & Open Voting'}
                                        </Button>
                                    </div>
                                )}
                            </div>
                            <CardDescription>
                                {validation_errors.length === 0
                                    ? 'All mandatory decision paper elements (exact motion, context, options/reason, recommendation, financial, service-user, risk) are verified.'
                                    : 'Incomplete drafts can be saved freely, but opening for voting requires completing all mandatory governance criteria:'}
                            </CardDescription>
                        </CardHeader>
                        {validation_errors.length > 0 && (
                            <CardContent className="pt-0">
                                <ul className="list-disc list-inside text-xs space-y-1 text-status-critical font-medium">
                                    {validation_errors.map((err, idx) => (
                                        <li key={idx}>{err}</li>
                                    ))}
                                </ul>
                            </CardContent>
                        )}
                        {openVotingError && (
                            <CardContent className="pt-0">
                                <div className="rounded bg-status-critical-bg p-2 text-xs text-status-critical">
                                    {openVotingError}
                                </div>
                            </CardContent>
                        )}
                    </Card>
                )}

                {/* Exact Motion Card */}
                <Card className="mb-6 border-primary/30 bg-card">
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Gavel className="h-4 w-4 text-primary" />
                                <CardTitle className="text-base font-semibold">Exact Motion</CardTitle>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant="outline" className="capitalize text-xs">
                                    {displayData.purpose ?? 'decision'} paper
                                </Badge>
                                {displayData.decision_type && (
                                    <Badge variant="secondary" className="capitalize text-xs">
                                        {displayData.decision_type}
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <blockquote className="border-l-4 border-primary pl-4 italic text-base font-medium text-foreground py-2.5 bg-primary/5 rounded-r">
                            {displayData.exact_motion || resolution.title}
                        </blockquote>
                    </CardContent>
                </Card>

                {/* Context / Background */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Context & Background (Why now?)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="whitespace-pre-wrap text-foreground leading-relaxed">
                            {displayData.context || 'No background context provided.'}
                        </p>
                    </CardContent>
                </Card>

                {/* Evaluated Options & Sole Option Reason */}
                <Card className="mb-6">
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-base font-semibold">
                                    Alternatives Evaluated ({options.length})
                                </CardTitle>
                                <CardDescription>
                                    Consequential decisions evaluate alternatives with explicit benefits and drawbacks.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {options.map((option, index) => (
                                <div
                                    key={index}
                                    className="rounded-lg border p-4 bg-muted/20 space-y-3 flex flex-col justify-between"
                                >
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <h4 className="font-semibold text-sm text-foreground">
                                                {option.label}
                                            </h4>
                                            <Badge variant="outline" className="text-xs">
                                                Option {index + 1}
                                            </Badge>
                                        </div>
                                        {option.description && (
                                            <p className="text-xs text-muted-foreground whitespace-pre-wrap">
                                                {option.description}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-2 pt-2 border-t text-xs">
                                        {option.benefits && (
                                            <div className="rounded bg-status-success-bg/20 border border-status-success/20 p-2">
                                                <p className="font-semibold text-status-success mb-0.5">
                                                    Benefits & Opportunities:
                                                </p>
                                                <p className="text-foreground/90 whitespace-pre-wrap">
                                                    {option.benefits}
                                                </p>
                                            </div>
                                        )}
                                        {option.drawbacks && (
                                            <div className="rounded bg-status-critical-bg/20 border border-status-critical/20 p-2">
                                                <p className="font-semibold text-status-critical mb-0.5">
                                                    Drawbacks, Costs & Risks:
                                                </p>
                                                <p className="text-foreground/90 whitespace-pre-wrap">
                                                    {option.drawbacks}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {options.length < 2 && displayData.single_option_reason && (
                            <div className="rounded-lg border border-status-warning/40 bg-status-warning-bg/15 p-4 space-y-1.5">
                                <div className="flex items-center gap-2 text-status-warning font-semibold text-xs uppercase tracking-wider">
                                    <AlertCircle className="h-4 w-4" />
                                    Sole Option Justification
                                </div>
                                <p className="text-xs text-foreground whitespace-pre-wrap">
                                    {displayData.single_option_reason}
                                </p>
                            </div>
                        )}

                        {/* Management Recommendation */}
                        {displayData.recommendation && (
                            <div className="mt-4 rounded-lg border border-status-info/30 bg-status-info-bg/30 p-4 space-y-1">
                                <p className="font-semibold text-xs uppercase tracking-wider text-status-info">
                                    Management Recommendation & Strategic Rationale
                                </p>
                                <p className="text-sm text-foreground whitespace-pre-wrap leading-relaxed">
                                    {displayData.recommendation}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Impact Assessment Cards */}
                <Card className="mb-6">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold flex items-center gap-2">
                            <Scale className="h-4 w-4 text-primary" />
                            Impact & Governance Assessments
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {/* Financial Impact */}
                            <div className="rounded-lg border p-3.5 space-y-1.5 bg-muted/10">
                                <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                    <DollarSign className="h-3.5 w-3.5 text-primary" />
                                    Financial Cost
                                </div>
                                {displayData.cost_impact?.has_cost ? (
                                    <div className="space-y-1 text-xs">
                                        <p className="text-base font-bold text-foreground">
                                            {displayData.cost_impact.currency ?? 'NZD'}{' '}
                                            {displayData.cost_impact.amount}
                                        </p>
                                        {displayData.cost_impact.budget_source && (
                                            <p className="text-muted-foreground">
                                                Fund: {displayData.cost_impact.budget_source}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Explicitly confirmed: No direct financial cost.
                                    </p>
                                )}
                            </div>

                            {/* Service-User & Safety Implications */}
                            <div className="rounded-lg border p-3.5 space-y-1.5 bg-muted/10">
                                <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                    <Users className="h-3.5 w-3.5 text-primary" />
                                    Service-User & Safety
                                </div>
                                <p className="text-xs text-foreground whitespace-pre-wrap">
                                    {displayData.service_user_implications || 'None specified.'}
                                </p>
                            </div>

                            {/* Risk Assessment & Equity */}
                            <div className="rounded-lg border p-3.5 space-y-1.5 bg-muted/10">
                                <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                    <ShieldAlert className="h-3.5 w-3.5 text-primary" />
                                    Risk & Equity
                                </div>
                                <p className="text-xs text-foreground whitespace-pre-wrap">
                                    {displayData.risk_equity_implications || 'None specified.'}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Voting Section */}
                {isOpen && can_vote && !my_vote && !my_conflict?.withdrew_from_voting && (
                    <Card className="mb-6 border-status-info/30">
                        <CardHeader>
                            <CardTitle>Cast Your Vote</CardTitle>
                            <CardDescription>
                                {resolution.deadline && (
                                    <span>
                                        Voting closes:{' '}
                                        {new Date(
                                            resolution.deadline,
                                        ).toLocaleString()}
                                    </span>
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RadioGroup
                                value={selectedVote}
                                onValueChange={setSelectedVote}
                                className="space-y-3"
                            >
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="for" />
                                    <span className="flex items-center gap-2">
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                        For
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="against" />
                                    <span className="flex items-center gap-2">
                                        <XCircle className="h-5 w-5 text-status-critical" />
                                        Against
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="abstain" />
                                    <span className="flex items-center gap-2">
                                        <MinusCircle className="h-5 w-5 text-muted-foreground" />
                                        Abstain
                                    </span>
                                </label>
                            </RadioGroup>

                            <div className="mt-4">
                                <label
                                    htmlFor="conflict"
                                    className="text-sm font-medium text-foreground"
                                >
                                    Vote Note (optional)
                                </label>
                                <Textarea
                                    id="conflict"
                                    placeholder="Optional explanation or reason for your vote..."
                                    value={conflictNote}
                                    onChange={(e) =>
                                        setConflictNote(e.target.value)
                                    }
                                    className="mt-1"
                                />
                            </div>

                            <div className="mt-6 flex flex-wrap items-center justify-between gap-3 pt-4 border-t">
                                <Button
                                    onClick={submitVote}
                                    disabled={!selectedVote || submitting}
                                >
                                    {submitting
                                        ? 'Submitting...'
                                        : 'Submit Vote'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConflictDialogOpen(true)}
                                >
                                    <AlertTriangle className="mr-2 h-4 w-4 text-status-warning" />
                                    Declare a Conflict...
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Conflict Declared Status */}
                {my_conflict?.withdrew_from_voting && (
                    <Card className="mb-6 border-status-warning/40 bg-status-warning-bg/10">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-status-warning">
                                <AlertTriangle className="h-5 w-5" />
                                Conflict Declared — Recused from Voting
                            </CardTitle>
                            <CardDescription>
                                You declared a conflict of interest on this resolution and withdrew from voting. Your seat is excluded from quorum participation.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm space-y-1">
                                <p><strong>Nature:</strong> {my_conflict.declaration_type}</p>
                                {my_conflict.declaration_text && (
                                    <p className="text-muted-foreground">{my_conflict.declaration_text}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* My Vote */}
                {my_vote && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Your Vote Receipt</CardTitle>
                            <CardDescription>
                                Official record of your recorded vote for {resolution.resolution_reference}.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-3">
                                <Badge
                                    className={cn(
                                        my_vote.vote === 'for' &&
                                            'bg-status-success-bg text-status-success',
                                        my_vote.vote === 'against' &&
                                            'bg-status-critical-bg text-status-critical',
                                        my_vote.vote === 'abstain' &&
                                            'bg-muted text-foreground',
                                    )}
                                >
                                    {my_vote.vote.toUpperCase()}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    Recorded{' '}
                                    {new Date(
                                        my_vote.voted_at,
                                    ).toLocaleString()}{' '}
                                    · Method: {my_vote.voting_method ?? 'Electronic'}
                                </span>
                                {my_vote.conflict_declared && (
                                    <Badge
                                        variant="outline"
                                        className="text-status-warning"
                                    >
                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                        Conflict Noted
                                    </Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Results */}
                {isClosed && results && (
                    <Card className="mb-6">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Voting Results</CardTitle>
                                    <CardDescription>
                                        {results.is_frozen
                                            ? 'Immutable decision snapshot captured at closure.'
                                            : 'Official voting tally.'}
                                    </CardDescription>
                                </div>
                                <Badge
                                    className={cn(
                                        results.outcome === 'carried' &&
                                            'bg-status-success-bg text-status-success',
                                        results.outcome === 'defeated' &&
                                            'bg-status-critical-bg text-status-critical',
                                        results.outcome === 'no_quorum' &&
                                            'bg-status-warning-bg text-status-warning',
                                    )}
                                >
                                    {results.outcome === 'no_quorum'
                                        ? 'No valid decision — quorum not met'
                                        : results.outcome}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {results.outcome === 'no_quorum' && (
                                <div className="mb-4 rounded-md border border-status-warning/30 bg-status-warning-bg/20 p-3 text-sm text-status-warning">
                                    <strong>No valid decision:</strong> Quorum was not met for this resolution. Required participation was not reached.
                                </div>
                            )}

                            <div className="mb-6 grid grid-cols-3 gap-4">
                                <div className="rounded-lg bg-status-success-bg p-4 text-center">
                                    <p className="text-3xl font-bold text-status-success">
                                        {results.summary.for}
                                    </p>
                                    <p className="text-sm text-status-success">
                                        For ({results.percentages.for}%)
                                    </p>
                                </div>
                                <div className="rounded-lg bg-status-critical-bg p-4 text-center">
                                    <p className="text-3xl font-bold text-status-critical">
                                        {results.summary.against}
                                    </p>
                                    <p className="text-sm text-status-critical">
                                        Against ({results.percentages.against}%)
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted p-4 text-center">
                                    <p className="text-3xl font-bold text-muted-foreground">
                                        {results.summary.abstain}
                                    </p>
                                    <p className="text-sm text-foreground">
                                        Abstain
                                    </p>
                                </div>
                            </div>

                            <h4 className="mb-2 font-medium">
                                Individual Votes
                            </h4>
                            <div className="space-y-2">
                                {results.individual_votes.map((vote, index) => (
                                    <div
                                        key={`${resolveMemberName(vote.board_member)}-${vote.voted_at}-${index}`}
                                        className="flex items-center justify-between rounded border p-2"
                                    >
                                        <span>
                                            {resolveMemberName(
                                                vote.board_member,
                                            )}
                                        </span>
                                        <Badge
                                            className={cn(
                                                vote.vote === 'for' &&
                                                    'bg-status-success-bg text-status-success',
                                                vote.vote === 'against' &&
                                                    'bg-status-critical-bg text-status-critical',
                                                vote.vote === 'abstain' &&
                                                    'bg-muted text-foreground',
                                            )}
                                        >
                                            {vote.vote}
                                        </Badge>
                                    </div>
                                ))}
                            </div>

                            {results.conflicts.length > 0 && (
                                <div className="mt-4">
                                    <h4 className="mb-2 font-medium">
                                        Conflict Declarations
                                    </h4>
                                    {results.conflicts.map((conflict, i) => (
                                        <p
                                            key={i}
                                            className="text-sm text-status-warning"
                                        >
                                            {resolveMemberName(
                                                conflict.board_member,
                                            )}{' '}
                                            - {conflict.type}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {isClosed && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Decision Summary</CardTitle>
                            <CardDescription>
                                Finalize the resolution once the outcome has
                                been actioned.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <Textarea
                                    placeholder="Outcome notes or implementation summary..."
                                    value={finalNotes}
                                    onChange={(e) =>
                                        setFinalNotes(e.target.value)
                                    }
                                />
                                {resolution.outcome_notes && (
                                    <p className="text-sm text-muted-foreground">
                                        Previous notes:{' '}
                                        {resolution.outcome_notes}
                                    </p>
                                )}
                                {canManage &&
                                    resolution.status === 'closed' && (
                                        <div className="flex gap-2">
                                            {resolution.outcome === 'carried' ? (
                                                <Button
                                                    onClick={() =>
                                                        finalizeResolution(
                                                            'implemented',
                                                        )
                                                    }
                                                    disabled={finalizing}
                                                >
                                                    Mark Implemented
                                                </Button>
                                            ) : (
                                                <Button
                                                    disabled
                                                    variant="secondary"
                                                    title="Only carried resolutions can be marked implemented"
                                                >
                                                    Mark Implemented (Requires Carried Outcome)
                                                </Button>
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    finalizeResolution(
                                                        'archived',
                                                    )
                                                }
                                                disabled={finalizing}
                                            >
                                                Archive
                                            </Button>
                                        </div>
                                    )}
                                {isFinalized && (
                                    <p className="text-sm text-muted-foreground">
                                        This resolution is {resolution.status}.
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Admin Actions */}
                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Admin Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex gap-2">
                            {resolution.status === 'draft' && (
                                <Button onClick={openVoting}>
                                    Open Voting
                                </Button>
                            )}
                            {resolution.status === 'open' && (
                                <Button
                                    onClick={closeVoting}
                                    variant="destructive"
                                >
                                    Close Voting
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card className="mt-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Paperclip className="h-5 w-5" />
                            Supporting documents
                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                ({attachments.length})
                            </span>
                        </CardTitle>
                        <CardDescription>
                            Background analyses, draft contracts, legal opinions
                            and other papers the board should review alongside
                            this resolution.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <GovernanceAttachmentsPanel
                            canManage={!!canManage}
                            attachments={attachments}
                            urls={{
                                upload: `/governance/resolutions/${resolution.id}/attachments`,
                                delete: (id) =>
                                    `/governance/resolutions/${resolution.id}/attachments/${id}`,
                            }}
                            reloadProp="attachments"
                            helperText="PDF, Office, images, CSV / TXT — up to 20 MB each."
                            emptyText={{
                                managed:
                                    'No supporting documents yet. Drop files above to attach one.',
                                readOnly:
                                    'No supporting documents have been attached to this resolution.',
                            }}
                        />
                    </CardContent>
                </Card>

                {/* Conflict Declaration Dialog */}
                <Dialog open={conflictDialogOpen} onOpenChange={setConflictDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Declare Conflict of Interest</DialogTitle>
                            <DialogDescription>
                                Formally declare an interest in {resolution.resolution_reference}. Withdrawing from voting excludes you from participation without recording an abstention vote.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-2">
                            <div>
                                <label className="text-sm font-medium text-foreground block mb-1">
                                    Nature of Conflict <span className="text-status-critical">*</span>
                                </label>
                                <select
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    value={conflictType}
                                    onChange={(e) => setConflictType(e.target.value)}
                                >
                                    <option value="material">Material personal interest</option>
                                    <option value="related">Related party transaction</option>
                                    <option value="prejudicial">Prejudicial bias or loyalty conflict</option>
                                    <option value="other">Other perceived conflict</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-foreground block mb-1">
                                    Affected Matter & Detail <span className="text-status-critical">*</span>
                                </label>
                                <Textarea
                                    placeholder="Describe the nature of your interest and affected decisions (minimum 20 characters)..."
                                    value={conflictDescription}
                                    onChange={(e) => setConflictDescription(e.target.value)}
                                    rows={4}
                                />
                                {conflictDescription.length > 0 && conflictDescription.length < 20 && (
                                    <p className="text-xs text-status-critical mt-1">
                                        Must be at least 20 characters ({conflictDescription.length}/20).
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2 pt-2 border-t">
                                <label className="flex items-start gap-2 cursor-pointer text-sm">
                                    <input
                                        type="checkbox"
                                        checked={conflictWithdrawVoting}
                                        onChange={(e) => setConflictWithdrawVoting(e.target.checked)}
                                        className="mt-1 h-4 w-4 rounded border-gray-300"
                                    />
                                    <span>
                                        <strong>Withdraw from voting</strong> (Excludes your seat from participation without recording an abstention vote)
                                    </span>
                                </label>
                                <label className="flex items-start gap-2 cursor-pointer text-sm">
                                    <input
                                        type="checkbox"
                                        checked={conflictWithdrawDiscussion}
                                        onChange={(e) => setConflictWithdrawDiscussion(e.target.checked)}
                                        className="mt-1 h-4 w-4 rounded border-gray-300"
                                    />
                                    <span>
                                        <strong>Withdraw from discussion</strong> (Leave the room / meeting during deliberation)
                                    </span>
                                </label>
                            </div>
                            <div className="rounded-md bg-muted p-3 text-xs text-muted-foreground">
                                Notice: Declaring a conflict and withdrawing does not reduce the quorum denominator (N). Quorum requires sufficient non-conflicted member participation.
                            </div>
                        </div>
                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button variant="outline" onClick={() => setConflictDialogOpen(false)} disabled={declaringConflict}>
                                Cancel
                            </Button>
                            <Button
                                onClick={handleDeclareConflict}
                                disabled={declaringConflict || conflictDescription.trim().length < 20}
                            >
                                {declaringConflict ? 'Submitting...' : 'Record Declaration'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Edit Decision Paper Wizard Dialog */}
                <ResolutionWizardDialog
                    isOpen={editDialogOpen}
                    onClose={() => setEditDialogOpen(false)}
                    meetings={meetings}
                    committees={committees}
                    resolution={resolution as any}
                    onCreated={() => router.reload()}
                />
            </PageLayout>
        </AppLayout>
    );
}
