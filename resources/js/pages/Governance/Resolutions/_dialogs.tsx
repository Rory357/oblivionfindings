import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { store as storeResolution } from '@/routes/governance/resolutions';
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    DollarSign,
    FileText,
    Gavel,
    Loader2,
    Plus,
    Scale,
    ScrollText,
    Trash2,
    Users,
    Vote,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ── Resolution type registry (Send-Kudos-style tile picker) ───────────────

type ResolutionTypeKey = 'ordinary' | 'special' | 'unanimous';

interface ResolutionTypeDef {
    key: ResolutionTypeKey;
    label: string;
    description: string;
    icon: LucideIcon;
    accent: string;
}

export const RESOLUTION_TYPES: ResolutionTypeDef[] = [
    {
        key: 'ordinary',
        label: 'Ordinary',
        description: 'Simple majority (>50%) carries.',
        icon: Vote,
        accent: 'text-status-info',
    },
    {
        key: 'special',
        label: 'Special',
        description: 'Two-thirds majority required.',
        icon: ScrollText,
        accent: 'text-status-warning',
    },
    {
        key: 'unanimous',
        label: 'Unanimous',
        description: 'All voting members must agree.',
        icon: Users,
        accent: 'text-primary',
    },
];

export function getResolutionType(
    value: string | null | undefined,
): ResolutionTypeDef {
    return (
        RESOLUTION_TYPES.find((t) => t.key === value) ?? RESOLUTION_TYPES[0]!
    );
}

// ── Form shapes ────────────────────────────────────────────────────────────

export type ResolutionFormValues = {
    title: string;
    description: string;
    type: ResolutionTypeKey | string;
    voting_deadline: string;
    meeting_id: string;
};

export interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string;
}

export interface CommitteeOption {
    id: number;
    name: string;
}

export interface ResolutionRecord {
    id: number;
    resolution_reference?: string;
    title: string;
    context?: string;
    exact_motion?: string;
    purpose?: string;
    decision_type?: string;
    voting_threshold?: string;
    type?: string;
    governance_meeting_id?: number | null;
    board_committee_id?: number | null;
    meeting?: { id: number; title: string; scheduled_at?: string } | null;
    committee?: { id: number; name: string } | null;
    options?: Array<{
        label: string;
        description: string;
        benefits: string;
        drawbacks: string;
    }>;
    single_option_reason?: string;
    recommendation?: string;
    cost_impact?: {
        has_cost?: boolean;
        amount?: string | number;
        currency?: string;
        budget_source?: string;
        note?: string;
    } | null;
    service_user_implications?: string;
    risk_equity_implications?: string;
    deadline?: string | null;
    voting_deadline?: string | null;
    follow_up_actions?: Array<{
        title: string;
        assignee_name: string;
        assigned_to?: number | null;
        due_date: string;
    }>;
    status?: string;
    version_number?: number;
    version?: number;
}

// ── Field helpers ─────────────────────────────────────────────────────────

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function ResolutionTypePicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: ResolutionTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {RESOLUTION_TYPES.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    <Button
                        unstyled
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                        aria-pressed={active}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', t.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {t.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {t.description}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}

// ── Wizard Steps Definition ───────────────────────────────────────────────

export const RESOLUTION_WIZARD_STEPS: readonly WizardStep[] = [
    {
        key: 'context',
        label: 'Context & Title',
        blurb: 'Title, linked meeting & background',
        icon: FileText,
    },
    {
        key: 'motion',
        label: 'Motion & Purpose',
        blurb: 'Exact motion, purpose & voting threshold',
        icon: Vote,
    },
    {
        key: 'options',
        label: 'Options & Recommendation',
        blurb: 'Evaluated options & management recommendation',
        icon: Scale,
    },
    {
        key: 'implications',
        label: 'Implications & Financials',
        blurb: 'Financial impact, safety & equity',
        icon: Users,
    },
    {
        key: 'review',
        label: 'Review & Submit',
        blurb: 'Deadlines, follow-up actions & confirmation',
        icon: Gavel,
    },
];

// ── Resolution Wizard Dialog (WizardShell) ───────────────────────────────

export interface UserOption {
    id: number;
    name: string;
    email?: string;
}

export interface ResolutionWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingOption[];
    committees?: CommitteeOption[];
    users?: UserOption[];
    meetingId?: number | string | null;
    lockMeeting?: boolean;
    resolution?: ResolutionRecord | null;
    onCreated?: (res?: ResolutionRecord) => void;
}

export function ResolutionWizardDialog({
    isOpen,
    onClose,
    meetings,
    committees = [],
    users = [],
    meetingId,
    lockMeeting = false,
    resolution = null,
    onCreated,
}: ResolutionWizardDialogProps) {
    if (!isOpen) return null;

    return (
        <ResolutionWizardBody
            onClose={onClose}
            meetings={meetings}
            committees={committees}
            users={users}
            meetingId={meetingId}
            lockMeeting={lockMeeting}
            resolution={resolution}
            onCreated={onCreated}
        />
    );
}

function ResolutionWizardBody({
    onClose,
    meetings,
    committees = [],
    users = [],
    meetingId,
    lockMeeting,
    resolution,
    onCreated,
}: {
    onClose: () => void;
    meetings: MeetingOption[];
    committees: CommitteeOption[];
    users: UserOption[];
    meetingId?: number | string | null;
    lockMeeting: boolean;
    resolution: ResolutionRecord | null;
    onCreated?: (res?: ResolutionRecord) => void;
}) {
    const isEdit = Boolean(resolution);
    const [showDiscardConfirm, setShowDiscardConfirm] = useState(false);
    const [isSubmitted, setIsSubmitted] = useState(false);
    const [savedResolution, setSavedResolution] = useState<ResolutionRecord | null>(resolution);

    const initialMeetingId =
        resolution?.governance_meeting_id != null
            ? String(resolution.governance_meeting_id)
            : resolution?.meeting?.id != null
              ? String(resolution.meeting.id)
              : meetingId != null
                ? String(meetingId)
                : 'none';

    const initialCommitteeId =
        resolution?.board_committee_id != null
            ? String(resolution.board_committee_id)
            : 'none';

    const initialType: ResolutionTypeKey =
        resolution?.voting_threshold === 'two_thirds'
            ? 'special'
            : resolution?.voting_threshold === 'unanimous'
              ? 'unanimous'
              : ((resolution?.type as ResolutionTypeKey) ?? 'ordinary');

    const [stepIndex, setStepIndex] = useState(0);
    const [hasFinancialCost, setHasFinancialCost] = useState<boolean>(
        Boolean(resolution?.cost_impact?.has_cost),
    );
    const [costAmount, setCostAmount] = useState<string>(
        resolution?.cost_impact?.amount != null
            ? String(resolution.cost_impact.amount)
            : '',
    );
    const [costCurrency, setCostCurrency] = useState<string>(
        resolution?.cost_impact?.currency ?? 'NZD',
    );
    const [costSource, setCostSource] = useState<string>(
        resolution?.cost_impact?.budget_source ?? '',
    );
    const [submitError, setSubmitError] = useState<string | null>(null);

    const form = useForm({
        title: resolution?.title ?? '',
        context: resolution?.context ?? '',
        exact_motion: resolution?.exact_motion ?? '',
        purpose: resolution?.purpose ?? 'decision',
        decision_type: resolution?.decision_type ?? 'strategic',
        type: initialType,
        meeting_id: initialMeetingId,
        board_committee_id: initialCommitteeId,
        options:
            resolution?.options && resolution.options.length > 0
                ? resolution.options
                : [
                      {
                          label: 'Option 1: Proposed action',
                          description: '',
                          benefits: '',
                          drawbacks: '',
                      },
                      {
                          label: 'Option 2: Status quo / Alternative',
                          description: '',
                          benefits: '',
                          drawbacks: '',
                      },
                  ],
        single_option_reason: resolution?.single_option_reason ?? '',
        recommendation: resolution?.recommendation ?? '',
        service_user_implications: resolution?.service_user_implications ?? '',
        risk_equity_implications: resolution?.risk_equity_implications ?? '',
        voting_deadline: resolution?.deadline
            ? resolution.deadline.slice(0, 16)
            : resolution?.voting_deadline
              ? resolution.voting_deadline.slice(0, 16)
              : '',
        follow_up_actions: (resolution?.follow_up_actions ?? []) as Array<{
            title: string;
            assignee_name: string;
            assigned_to?: number | null;
            due_date: string;
        }>,
        publish_now: false,
        expected_version:
            resolution?.version_number ?? resolution?.version ?? 1,
    });

    const addOption = () => {
        form.setData('options', [
            ...form.data.options,
            {
                label: `Option ${form.data.options.length + 1}`,
                description: '',
                benefits: '',
                drawbacks: '',
            },
        ]);
    };

    const removeOption = (idx: number) => {
        if (form.data.options.length <= 1) return;
        form.setData(
            'options',
            form.data.options.filter((_, i) => i !== idx),
        );
    };

    const updateOption = (
        idx: number,
        field: 'label' | 'description' | 'benefits' | 'drawbacks',
        value: string,
    ) => {
        const updated = [...form.data.options];
        updated[idx] = { ...updated[idx], [field]: value };
        form.setData('options', updated);
    };

    const addAction = () => {
        form.setData('follow_up_actions', [
            ...form.data.follow_up_actions,
            { title: '', assignee_name: '', assigned_to: null, due_date: '' },
        ]);
    };

    const removeAction = (idx: number) => {
        form.setData(
            'follow_up_actions',
            form.data.follow_up_actions.filter((_, i) => i !== idx),
        );
    };

    const updateAction = (
        idx: number,
        field: 'title' | 'assignee_name' | 'assigned_to' | 'due_date',
        value: any,
    ) => {
        const updated = [...form.data.follow_up_actions];
        updated[idx] = { ...updated[idx], [field]: value };
        form.setData('follow_up_actions', updated);
    };

    const publicationErrors = useMemo(() => {
        const missing: string[] = [];
        if (!form.data.title.trim()) missing.push('Title is required.');
        if (!form.data.exact_motion.trim())
            missing.push('Exact motion wording is required.');
        if (!form.data.context.trim())
            missing.push('Background context / rationale is required.');

        if (form.data.purpose === 'decision') {
            const validOptions = form.data.options.filter(
                (o) => o.label.trim().length > 0,
            );
            if (
                validOptions.length < 2 &&
                !form.data.single_option_reason.trim()
            ) {
                missing.push(
                    'At least 2 evaluated options or single-option justification required.',
                );
            }
            if (!form.data.recommendation.trim()) {
                missing.push('Management recommendation is required.');
            }
            if (hasFinancialCost && !costAmount.trim()) {
                missing.push('Financial cost amount must be specified.');
            }
            if (!form.data.service_user_implications.trim()) {
                missing.push('Service-user & safety implications required.');
            }
            if (!form.data.risk_equity_implications.trim()) {
                missing.push('Risk & equity implications required.');
            }
        }
        return missing;
    }, [form.data, hasFinancialCost, costAmount]);

    const isPublishReady = publicationErrors.length === 0;

    const completenessPct = useMemo(() => {
        let score = 0;
        if (form.data.title.trim()) score += 15;
        if (form.data.exact_motion.trim()) score += 20;
        if (form.data.context.trim()) score += 15;
        if (
            form.data.recommendation.trim() ||
            form.data.purpose !== 'decision'
        )
            score += 15;
        if (
            form.data.options.length >= 2 ||
            form.data.single_option_reason.trim()
        )
            score += 15;
        if (
            form.data.service_user_implications.trim() ||
            form.data.risk_equity_implications.trim()
        )
            score += 10;
        if (form.data.voting_deadline.trim()) score += 10;
        return Math.min(100, score);
    }, [form.data]);

    const handleSubmit = (publishNow: boolean) => {
        setSubmitError(null);
        const costImpact = hasFinancialCost
            ? {
                  has_cost: true,
                  amount: costAmount,
                  currency: costCurrency,
                  budget_source: costSource,
              }
            : {
                  has_cost: false,
                  note: 'Explicitly confirmed: No direct financial implications.',
              };

        form.transform((current) => ({
            ...current,
            meeting_id:
                current.meeting_id === 'none' ? null : current.meeting_id,
            board_committee_id:
                current.board_committee_id === 'none'
                    ? null
                    : current.board_committee_id,
            cost_impact: costImpact,
            publish_now: publishNow,
        }));

        if (isEdit && resolution) {
            form.put(`/governance/resolutions/${resolution.id}`, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page: any) => {
                    const updated = (page?.props?.resolution as ResolutionRecord) ?? resolution;
                    setSavedResolution(updated);
                    setIsSubmitted(true);
                    onCreated?.(updated);
                },
                onError: (errs) => {
                    if (errs && Object.keys(errs).length > 0) {
                        setSubmitError(Object.values(errs)[0]);
                    }
                },
            });
        } else {
            form.post(storeResolution.url(), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page: any) => {
                    const created = page?.props?.resolution as ResolutionRecord | undefined;
                    setSavedResolution(created ?? null);
                    setIsSubmitted(true);
                    onCreated?.(created);
                },
                onError: (errs) => {
                    if (errs && Object.keys(errs).length > 0) {
                        setSubmitError(Object.values(errs)[0]);
                    }
                },
            });
        }
    };

    const handleAttemptClose = () => {
        if (form.isDirty && !isSubmitted) {
            setShowDiscardConfirm(true);
        } else {
            onClose();
        }
    };

    if (isSubmitted) {
        return (
            <WizardShell
                open={true}
                onClose={onClose}
                title={isEdit ? 'Decision Paper Updated' : 'Decision Paper Created'}
                description="Structured decision paper authoring ensuring informed, accountable governance."
                railIcon={Gavel}
                railTitle={isEdit ? 'Updated' : 'Created'}
                railSub={
                    isEdit
                        ? `v${savedResolution?.version_number ?? form.data.expected_version} · Draft`
                        : 'Board decision'
                }
                steps={RESOLUTION_WIZARD_STEPS}
                stepIndex={4}
                onStepClick={() => {}}
                pct={100}
                pctLabel="Complete"
                footerStart={null}
                footerEnd={
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                }
            >
                <WizardSuccessPane
                    title={isEdit ? 'Decision Paper Updated' : 'Decision Paper Created'}
                    blurb={
                        isEdit
                            ? `Decision paper "${form.data.title}" has been updated to revision v${savedResolution?.version_number ?? form.data.expected_version}.`
                            : `Decision paper "${form.data.title}" has been created and registered.`
                    }
                    actions={
                        <Button type="button" onClick={onClose}>
                            Close
                        </Button>
                    }
                />
            </WizardShell>
        );
    }

    const lockedMeeting = lockMeeting
        ? (meetings.find((m) => String(m.id) === form.data.meeting_id) ?? null)
        : null;

    return (
        <WizardShell
            open={true}
            onClose={handleAttemptClose}
            title={isEdit ? 'Edit Decision Paper' : 'Author Decision Paper'}
            description="Structured decision paper authoring ensuring informed, accountable governance."
            railIcon={Gavel}
            railTitle={isEdit ? 'Edit Paper' : 'New Paper'}
            railSub={
                isEdit
                    ? `v${form.data.expected_version} · Draft`
                    : 'Board decision'
            }
            steps={RESOLUTION_WIZARD_STEPS}
            stepIndex={stepIndex}
            onStepClick={(idx) => setStepIndex(idx)}
            pct={completenessPct}
            pctLabel="Completeness"
            footerStart={
                stepIndex > 0 ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStepIndex((s) => s - 1)}
                    >
                        Back
                    </Button>
                ) : (
                    <Button type="button" variant="outline" onClick={handleAttemptClose}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                <div className="flex items-center gap-2">
                    {submitError && (
                        <span className="text-xs text-status-critical">
                            {submitError}
                        </span>
                    )}
                    {stepIndex < 4 ? (
                        <Button
                            type="button"
                            onClick={() => setStepIndex((s) => s + 1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <div className="flex items-center gap-2">
                            {!isEdit && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => handleSubmit(false)}
                                >
                                    {form.processing && (
                                        <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    )}
                                    Save draft
                                </Button>
                            )}
                            <Button
                                type="button"
                                disabled={
                                    form.processing ||
                                    (!isEdit && !isPublishReady)
                                }
                                onClick={() =>
                                    handleSubmit(!isEdit && isPublishReady)
                                }
                            >
                                {form.processing && (
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                )}
                                {isEdit
                                    ? 'Save changes'
                                    : isPublishReady
                                      ? 'Publish for voting'
                                      : 'Save draft'}
                            </Button>
                        </div>
                    )}
                </div>
            }
        >
            <div className="space-y-4 p-5">
                {/* Step 0: Context & Title */}
                {stepIndex === 0 && (
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="wiz-title">
                                Decision Paper Title{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Input
                                id="wiz-title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Approval of 2026/27 Strategic Plan"
                                required
                            />
                            <FieldError message={form.errors.title} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="wiz-meeting">
                                    Linked Meeting
                                </Label>
                                {lockedMeeting ? (
                                    <div className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/10 p-2 text-sm">
                                        <Gavel className="h-4 w-4 text-primary" />
                                        <span className="truncate font-medium">
                                            {lockedMeeting.title}
                                        </span>
                                    </div>
                                ) : (
                                    <Select
                                        value={form.data.meeting_id}
                                        onValueChange={(v) =>
                                            form.setData('meeting_id', v)
                                        }
                                    >
                                        <SelectTrigger id="wiz-meeting">
                                            <SelectValue placeholder="No linked meeting" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                No linked meeting
                                            </SelectItem>
                                            {meetings.map((m) => (
                                                <SelectItem
                                                    key={m.id}
                                                    value={String(m.id)}
                                                >
                                                    {m.title} (
                                                    {new Date(
                                                        m.scheduled_at,
                                                    ).toLocaleDateString(
                                                        'en-NZ',
                                                    )}
                                                    )
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <FieldError message={form.errors.meeting_id} />
                            </div>

                            <div>
                                <Label htmlFor="wiz-committee">
                                    Board Committee
                                </Label>
                                <Select
                                    value={form.data.board_committee_id}
                                    onValueChange={(v) =>
                                        form.setData('board_committee_id', v)
                                    }
                                >
                                    <SelectTrigger id="wiz-committee">
                                        <SelectValue placeholder="Whole Board (No Committee)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Whole Board (No Committee)
                                        </SelectItem>
                                        {committees.map((c) => (
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

                        <div>
                            <Label htmlFor="wiz-context">
                                Background Context & Rationale{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="wiz-context"
                                rows={4}
                                value={form.data.context}
                                onChange={(e) =>
                                    form.setData('context', e.target.value)
                                }
                                placeholder="Explain why this decision is being brought to the board, relevant background, and previous decisions."
                            />
                            <FieldError message={form.errors.context} />
                        </div>
                    </div>
                )}

                {/* Step 1: Motion & Purpose */}
                {stepIndex === 1 && (
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="wiz-motion">
                                Exact Motion Wording{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="wiz-motion"
                                rows={3}
                                value={form.data.exact_motion}
                                onChange={(e) =>
                                    form.setData('exact_motion', e.target.value)
                                }
                                placeholder="e.g. That the Board resolves to approve the 2026/27 Strategic Plan as presented."
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                The formal operative motion as it will appear in
                                the minutes and on the voting ballot.
                            </p>
                            <FieldError message={form.errors.exact_motion} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="wiz-purpose">
                                    Paper Purpose
                                </Label>
                                <Select
                                    value={form.data.purpose}
                                    onValueChange={(v) =>
                                        form.setData('purpose', v)
                                    }
                                >
                                    <SelectTrigger id="wiz-purpose">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="decision">
                                            Decision — formal board resolution
                                        </SelectItem>
                                        <SelectItem value="noting">
                                            Noting — for board information
                                        </SelectItem>
                                        <SelectItem value="discussion">
                                            Discussion — exploratory feedback
                                        </SelectItem>
                                        <SelectItem value="endorsement">
                                            Endorsement — committee
                                            recommendation
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="wiz-decision-type">
                                    Decision Category
                                </Label>
                                <Select
                                    value={form.data.decision_type}
                                    onValueChange={(v) =>
                                        form.setData('decision_type', v)
                                    }
                                >
                                    <SelectTrigger id="wiz-decision-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="strategic">
                                            Strategic
                                        </SelectItem>
                                        <SelectItem value="operational">
                                            Operational
                                        </SelectItem>
                                        <SelectItem value="financial">
                                            Financial / Spend
                                        </SelectItem>
                                        <SelectItem value="statutory">
                                            Statutory & Regulatory
                                        </SelectItem>
                                        <SelectItem value="governance">
                                            Governance & Constitution
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div>
                            <Label className="mb-2 block">
                                Voting Threshold Rule{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <ResolutionTypePicker
                                value={form.data.type}
                                onChange={(v) => form.setData('type', v)}
                            />
                        </div>
                    </div>
                )}

                {/* Step 2: Options & Recommendation */}
                {stepIndex === 2 && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <Label className="text-sm font-semibold">
                                    Evaluated Alternatives
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Good governance requires evaluating viable
                                    alternatives (minimum 2 recommended).
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addOption}
                            >
                                <Plus className="mr-1 h-3.5 w-3.5" /> Add option
                            </Button>
                        </div>

                        <div className="space-y-3">
                            {form.data.options.map((opt, idx) => (
                                <div
                                    key={idx}
                                    className="space-y-2 rounded-lg border border-border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <Input
                                            value={opt.label}
                                            onChange={(e) =>
                                                updateOption(
                                                    idx,
                                                    'label',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={`Option ${idx + 1} title`}
                                            className="h-8 font-medium"
                                        />
                                        {form.data.options.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeOption(idx)
                                                }
                                                className="text-status-critical hover:text-status-critical"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        )}
                                    </div>
                                    <Textarea
                                        rows={2}
                                        value={opt.description}
                                        onChange={(e) =>
                                            updateOption(
                                                idx,
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Description of this option..."
                                        className="text-xs"
                                    />
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <Input
                                            value={opt.benefits}
                                            onChange={(e) =>
                                                updateOption(
                                                    idx,
                                                    'benefits',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Benefits / Pros"
                                            className="h-7 text-xs"
                                        />
                                        <Input
                                            value={opt.drawbacks}
                                            onChange={(e) =>
                                                updateOption(
                                                    idx,
                                                    'drawbacks',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Risks / Cons"
                                            className="h-7 text-xs"
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>

                        {form.data.options.length < 2 && (
                            <div>
                                <Label htmlFor="wiz-single-reason">
                                    Single Option Justification{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="wiz-single-reason"
                                    rows={2}
                                    value={form.data.single_option_reason}
                                    onChange={(e) =>
                                        form.setData(
                                            'single_option_reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Explain why only a single option is being presented (e.g. sole provider, statutory mandate)."
                                />
                            </div>
                        )}

                        <div>
                            <Label htmlFor="wiz-rec">
                                Management Recommendation & Rationale{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="wiz-rec"
                                rows={3}
                                value={form.data.recommendation}
                                onChange={(e) =>
                                    form.setData(
                                        'recommendation',
                                        e.target.value,
                                    )
                                }
                                placeholder="Which option does management recommend and why?"
                            />
                        </div>
                    </div>
                )}

                {/* Step 3: Implications & Financials */}
                {stepIndex === 3 && (
                    <div className="space-y-4">
                        <div className="rounded-lg border border-border p-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <Label className="text-sm font-medium">
                                        Financial Cost Implications
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Does this proposal involve unbudgeted
                                        spend or budget adjustment?
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant={
                                        hasFinancialCost ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    onClick={() =>
                                        setHasFinancialCost(!hasFinancialCost)
                                    }
                                >
                                    <DollarSign className="mr-1 h-3.5 w-3.5" />
                                    {hasFinancialCost
                                        ? 'Has direct cost'
                                        : 'No direct cost'}
                                </Button>
                            </div>

                            {hasFinancialCost && (
                                <div className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-3">
                                    <div>
                                        <Label htmlFor="wiz-cost-amt">
                                            Cost Amount{' '}
                                            <span className="text-status-critical">
                                                *
                                            </span>
                                        </Label>
                                        <Input
                                            id="wiz-cost-amt"
                                            value={costAmount}
                                            onChange={(e) =>
                                                setCostAmount(e.target.value)
                                            }
                                            placeholder="e.g. 45000"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="wiz-cost-curr">
                                            Currency
                                        </Label>
                                        <Select
                                            value={costCurrency}
                                            onValueChange={setCostCurrency}
                                        >
                                            <SelectTrigger id="wiz-cost-curr">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="NZD">
                                                    NZD ($)
                                                </SelectItem>
                                                <SelectItem value="AUD">
                                                    AUD ($)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label htmlFor="wiz-cost-src">
                                            Budget Line / Source
                                        </Label>
                                        <Input
                                            id="wiz-cost-src"
                                            value={costSource}
                                            onChange={(e) =>
                                                setCostSource(e.target.value)
                                            }
                                            placeholder="e.g. IT Capex 2026"
                                        />
                                    </div>
                                </div>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="wiz-service-user">
                                Service-User Safety & Quality Implications{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="wiz-service-user"
                                rows={3}
                                value={form.data.service_user_implications}
                                onChange={(e) =>
                                    form.setData(
                                        'service_user_implications',
                                        e.target.value,
                                    )
                                }
                                placeholder="How does this decision affect supported residents, service quality, or safeguarding?"
                            />
                        </div>

                        <div>
                            <Label htmlFor="wiz-equity">
                                Risk & Te Tiriti / Equity Implications{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                id="wiz-equity"
                                rows={3}
                                value={form.data.risk_equity_implications}
                                onChange={(e) =>
                                    form.setData(
                                        'risk_equity_implications',
                                        e.target.value,
                                    )
                                }
                                placeholder="Impact on organizational risk appetite, compliance, and Te Tiriti o Waitangi principles."
                            />
                        </div>
                    </div>
                )}

                {/* Step 4: Review & Submit */}
                {stepIndex === 4 && (
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="wiz-deadline">Voting Deadline</Label>
                            <Input
                                id="wiz-deadline"
                                type="datetime-local"
                                value={form.data.voting_deadline}
                                onChange={(e) =>
                                    form.setData(
                                        'voting_deadline',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Cut-off date and time for board votes to be
                                registered.
                            </p>
                        </div>

                        <div>
                            <div className="flex items-center justify-between">
                                <Label className="text-sm font-semibold">
                                    Implementation Actions
                                </Label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addAction}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" /> Add
                                    action
                                </Button>
                            </div>
                            <div className="mt-2 space-y-2">
                                {form.data.follow_up_actions.map(
                                    (act, actIdx) => (
                                        <div
                                            key={actIdx}
                                            className="flex items-center gap-2"
                                        >
                                            <Input
                                                value={act.title}
                                                onChange={(e) =>
                                                    updateAction(
                                                        actIdx,
                                                        'title',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Action task"
                                                className="h-8 text-xs flex-1"
                                            />
                                            {users && users.length > 0 ? (
                                                <Select
                                                    value={act.assigned_to != null ? String(act.assigned_to) : ''}
                                                    onValueChange={(val) => {
                                                        const selected = users.find((u) => String(u.id) === val);
                                                        updateAction(actIdx, 'assigned_to', val ? Number(val) : null);
                                                        if (selected) {
                                                            updateAction(actIdx, 'assignee_name', selected.name);
                                                        }
                                                    }}
                                                >
                                                    <SelectTrigger className="h-8 text-xs min-w-[130px] max-w-[170px]">
                                                        <SelectValue placeholder={act.assignee_name || 'Assignee'} />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {users.map((u) => (
                                                            <SelectItem key={u.id} value={String(u.id)}>
                                                                {u.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <Input
                                                    value={act.assignee_name}
                                                    onChange={(e) =>
                                                        updateAction(
                                                            actIdx,
                                                            'assignee_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Assignee"
                                                    className="h-8 text-xs w-[130px]"
                                                />
                                            )}
                                            <Input
                                                type="date"
                                                value={act.due_date}
                                                onChange={(e) =>
                                                    updateAction(
                                                        actIdx,
                                                        'due_date',
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-8 text-xs w-[130px]"
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeAction(actIdx)
                                                }
                                                className="text-status-critical"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    ),
                                )}
                            </div>
                        </div>

                        {/* Readiness notice */}
                        <div
                            className={cn(
                                'rounded-lg border p-3 text-xs',
                                isPublishReady
                                    ? 'border-status-success/40 bg-status-success-bg text-status-success'
                                    : 'border-status-warning/40 bg-status-warning-bg text-status-warning',
                            )}
                        >
                            <div className="flex items-center gap-2 font-medium">
                                {isPublishReady ? (
                                    <CheckCircle2 className="h-4 w-4 shrink-0" />
                                ) : (
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                )}
                                <span>
                                    {isPublishReady
                                        ? 'Paper meets all formal publication standards.'
                                        : 'Paper is an incomplete draft. You can still save it as a draft.'}
                                </span>
                            </div>
                            {!isPublishReady && (
                                <ul className="mt-1 list-inside list-disc space-y-0.5 pl-2 text-[11px] opacity-90">
                                    {publicationErrors.map((err, i) => (
                                        <li key={i}>{err}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={showDiscardConfirm}
                onClose={() => setShowDiscardConfirm(false)}
                onConfirm={() => {
                    setShowDiscardConfirm(false);
                    onClose();
                }}
                title="Discard Unsaved Changes?"
                description="You have unsaved changes in this decision paper. Are you sure you want to close without saving?"
                confirmText="Discard Changes"
                variant="destructive"
            />
        </WizardShell>
    );
}

// ── Backwards-compatible NewResolutionDialog export ───────────────────────

export function NewResolutionDialog(props: ResolutionWizardDialogProps) {
    return <ResolutionWizardDialog {...props} />;
}
