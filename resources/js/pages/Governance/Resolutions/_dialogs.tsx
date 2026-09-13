import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { store as storeResolution } from '@/routes/governance/resolutions';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    Briefcase,
    CheckCircle2,
    ChevronLeft,
    ClipboardCheck,
    DollarSign,
    FileText,
    Gavel,
    Landmark,
    Link2,
    ListChecks,
    Loader2,
    MessageSquare,
    Plus,
    Scale,
    ScrollText,
    ShieldAlert,
    Trash2,
    TrendingUp,
    Users,
    Vote,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

// ── Registries (tile pickers) ───────────────────────────────────────────────

type ResolutionTypeKey = 'ordinary' | 'special' | 'unanimous';

interface TileDef<K extends string = string> {
    key: K;
    label: string;
    description: string;
    icon: LucideIcon;
    accent?: string;
}

export const RESOLUTION_TYPES: TileDef<ResolutionTypeKey>[] = [
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

export function getResolutionType(value: string | null | undefined) {
    return (
        RESOLUTION_TYPES.find((t) => t.key === value) ?? RESOLUTION_TYPES[0]!
    );
}

/** Purposes accepted by Store/UpdateResolutionRequest. */
const PURPOSES: TileDef[] = [
    {
        key: 'decision',
        label: 'For decision',
        description: 'A formal vote is required.',
        icon: Gavel,
    },
    {
        key: 'discussion',
        label: 'For discussion',
        description: 'Strategic steering and feedback.',
        icon: MessageSquare,
    },
    {
        key: 'information',
        label: 'For information',
        description: 'Noting only — no vote.',
        icon: BookOpen,
    },
];

const DECISION_CATEGORIES: TileDef[] = [
    {
        key: 'strategic',
        label: 'Strategic',
        description: 'Direction and priorities.',
        icon: TrendingUp,
    },
    {
        key: 'financial',
        label: 'Financial',
        description: 'Budget, spend and funding.',
        icon: DollarSign,
    },
    {
        key: 'policy',
        label: 'Policy',
        description: 'Policy and compliance.',
        icon: ScrollText,
    },
    {
        key: 'operational',
        label: 'Operational',
        description: 'Service risk and safety.',
        icon: Briefcase,
    },
    {
        key: 'statutory',
        label: 'Statutory',
        description: 'Legal and regulatory.',
        icon: Landmark,
    },
    {
        key: 'governance',
        label: 'Governance',
        description: 'Constitution and board rules.',
        icon: Scale,
    },
];

const CURRENCIES = ['NZD', 'AUD', 'USD', 'GBP', 'EUR'];

const NONE = '__none';

// ── Shapes ─────────────────────────────────────────────────────────────────

export interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string | null;
}

export interface CommitteeOption {
    id: number;
    name: string;
}

export interface UserOption {
    id: number;
    name: string;
    email?: string;
}

export interface AuthoritySubjectOption {
    id: number;
    label: string;
}

/** Selectable records keyed by the authority service's group keys. */
export type AuthoritySubjects = Record<
    string,
    AuthoritySubjectOption[] | undefined
>;

/** One server-described group of bindable records. */
export interface AuthoritySubjectGroup {
    key: string;
    subject_type: string;
    label: string;
}

export interface AuthorityBinding {
    id: number;
    subject_type: string;
    subject_id: number;
    subject_label: string;
    subject_type_label: string;
    subject_revision?: string | number | null;
    bound_at?: string | null;
    consumed_at?: string | null;
}

type OptionRow = {
    label: string;
    description: string;
    benefits: string;
    drawbacks: string;
};

type FollowUpRow = {
    title: string;
    assignee_name: string;
    assigned_to: number | null;
    due_date: string;
};

export interface ResolutionRecord {
    id: number;
    resolution_reference?: string;
    title: string;
    context?: string | null;
    exact_motion?: string | null;
    purpose?: string | null;
    decision_type?: string | null;
    voting_threshold?: string | null;
    governance_meeting_id?: number | null;
    board_committee_id?: number | null;
    meeting?: { id: number; title: string; scheduled_at?: string } | null;
    committee?: { id: number; name: string } | null;
    options?: Array<Partial<OptionRow>> | null;
    single_option_reason?: string | null;
    recommendation?: string | null;
    cost_impact?: {
        has_cost?: boolean;
        is_none?: boolean;
        amount?: string | number | null;
        currency?: string | null;
        budget_source?: string | null;
        funding_source?: string | null;
    } | null;
    service_user_implications?: string | null;
    risk_equity_implications?: string | null;
    deadline?: string | null;
    follow_up_actions?: Array<Partial<FollowUpRow>> | null;
    status?: string;
    version_number?: number;
}

type StepKey =
    | 'paper'
    | 'motion'
    | 'options'
    | 'implications'
    | 'voting'
    | 'review';

export const RESOLUTION_WIZARD_STEPS: readonly (WizardStep & {
    key: StepKey;
})[] = [
    {
        key: 'paper',
        label: 'Paper & meeting',
        blurb: 'Title, meeting, committee & background',
        icon: FileText,
    },
    {
        key: 'motion',
        label: 'Motion & approval',
        blurb: 'Exact motion, purpose & record approved',
        icon: Gavel,
    },
    {
        key: 'options',
        label: 'Options',
        blurb: 'Alternatives & recommendation',
        icon: Scale,
    },
    {
        key: 'implications',
        label: 'Implications',
        blurb: 'Cost, service-user safety, risk & equity',
        icon: ShieldAlert,
    },
    {
        key: 'voting',
        label: 'Voting & actions',
        blurb: 'Threshold, deadline & follow-up actions',
        icon: Vote,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check readiness and save',
        icon: ClipboardCheck,
    },
];

const STEP_INDEX = Object.fromEntries(
    RESOLUTION_WIZARD_STEPS.map((step, index) => [step.key, index]),
) as Record<StepKey, number>;

/** Server validation keys → the wizard step that owns the field. */
const FIELD_STEPS: Record<string, StepKey> = {
    title: 'paper',
    meeting_id: 'paper',
    governance_meeting_id: 'paper',
    board_committee_id: 'paper',
    context: 'paper',
    description: 'paper',
    exact_motion: 'motion',
    purpose: 'motion',
    decision_type: 'motion',
    authority_binding: 'motion',
    options: 'options',
    single_option_reason: 'options',
    recommendation: 'options',
    cost_impact: 'implications',
    risk_impact: 'implications',
    service_user_implications: 'implications',
    risk_equity_implications: 'implications',
    type: 'voting',
    voting_threshold: 'voting',
    voting_deadline: 'voting',
    follow_up_actions: 'voting',
    quorum_required: 'voting',
    expected_version: 'review',
    publish_now: 'review',
};

type WizardData = {
    title: string;
    context: string;
    meeting_id: string;
    board_committee_id: string;
    exact_motion: string;
    purpose: string;
    decision_type: string;
    authority_binding_key: string;
    options: OptionRow[];
    single_option_reason: string;
    recommendation: string;
    has_cost: boolean;
    cost_amount: string;
    cost_currency: string;
    cost_source: string;
    service_user_implications: string;
    risk_equity_implications: string;
    type: string;
    voting_deadline: string;
    follow_up_actions: FollowUpRow[];
};

const DEFAULT_OPTIONS: OptionRow[] = [
    {
        label: 'Option 1: Proposed action',
        description: '',
        benefits: '',
        drawbacks: '',
    },
    {
        label: 'Option 2: Status quo / alternative',
        description: '',
        benefits: '',
        drawbacks: '',
    },
];

function bindingKey(type: string, id: number | string): string {
    return `${type}:${id}`;
}

function thresholdType(threshold: string | null | undefined): string {
    switch (threshold) {
        case 'two_thirds':
            return 'special';
        case 'unanimous':
            return 'unanimous';
        case 'simple_majority':
            return 'ordinary';
        default:
            return threshold ? '' : 'ordinary';
    }
}

function initialData(
    resolution: ResolutionRecord | null,
    meetingId: number | string | null | undefined,
    bindings: AuthorityBinding[],
): WizardData {
    const cost = resolution?.cost_impact ?? null;
    const meeting =
        resolution?.governance_meeting_id ??
        resolution?.meeting?.id ??
        (resolution ? null : meetingId);
    const currentBinding = bindings[0];

    return {
        title: resolution?.title ?? '',
        context: resolution?.context ?? '',
        meeting_id: meeting != null && meeting !== '' ? String(meeting) : NONE,
        board_committee_id:
            resolution?.board_committee_id != null
                ? String(resolution.board_committee_id)
                : NONE,
        exact_motion: resolution?.exact_motion ?? '',
        purpose: resolution
            ? PURPOSES.some((p) => p.key === resolution.purpose)
                ? (resolution.purpose as string)
                : ''
            : 'decision',
        decision_type: resolution
            ? (resolution.decision_type ?? '')
            : 'strategic',
        authority_binding_key: currentBinding
            ? bindingKey(currentBinding.subject_type, currentBinding.subject_id)
            : NONE,
        options:
            resolution?.options && resolution.options.length > 0
                ? resolution.options.map((o) => ({
                      label: o.label ?? '',
                      description: o.description ?? '',
                      benefits: o.benefits ?? '',
                      drawbacks: o.drawbacks ?? '',
                  }))
                : resolution
                  ? []
                  : DEFAULT_OPTIONS,
        single_option_reason: resolution?.single_option_reason ?? '',
        recommendation: resolution?.recommendation ?? '',
        has_cost:
            Boolean(cost?.has_cost) ||
            (cost?.amount != null && Number(cost.amount) > 0),
        cost_amount:
            cost?.amount != null && Number(cost.amount) > 0
                ? String(cost.amount)
                : '',
        cost_currency: cost?.currency ?? 'NZD',
        cost_source: cost?.budget_source ?? cost?.funding_source ?? '',
        service_user_implications: resolution?.service_user_implications ?? '',
        risk_equity_implications: resolution?.risk_equity_implications ?? '',
        type: resolution
            ? thresholdType(resolution.voting_threshold)
            : 'ordinary',
        voting_deadline: resolution?.deadline
            ? resolution.deadline.slice(0, 16)
            : '',
        follow_up_actions: (resolution?.follow_up_actions ?? []).map((a) => ({
            title: a.title ?? '',
            assignee_name: a.assignee_name ?? '',
            assigned_to: a.assigned_to ?? null,
            due_date: a.due_date ?? '',
        })),
    };
}

/** The server payload for a set of wizard values (before edit diffing). */
function toPayload(data: WizardData): Record<string, unknown> {
    const [subjectType, subjectId] = data.authority_binding_key.split(':');

    return {
        title: data.title.trim(),
        context: data.context,
        meeting_id: data.meeting_id === NONE ? null : Number(data.meeting_id),
        board_committee_id:
            data.board_committee_id === NONE
                ? null
                : Number(data.board_committee_id),
        exact_motion: data.exact_motion,
        purpose: data.purpose || null,
        decision_type: data.decision_type || null,
        authority_binding:
            data.authority_binding_key === NONE || subjectId === undefined
                ? null
                : { subject_type: subjectType, subject_id: Number(subjectId) },
        options: data.options,
        single_option_reason: data.single_option_reason,
        recommendation: data.recommendation,
        cost_impact: data.has_cost
            ? {
                  has_cost: true,
                  amount: data.cost_amount,
                  currency: data.cost_currency,
                  budget_source: data.cost_source,
              }
            : {
                  has_cost: false,
                  note: 'Explicitly confirmed: No direct financial implications.',
              },
        service_user_implications: data.service_user_implications,
        risk_equity_implications: data.risk_equity_implications,
        type: data.type || null,
        voting_deadline: data.voting_deadline || null,
        follow_up_actions: data.follow_up_actions.filter(
            (a) => a.title.trim() !== '',
        ),
    };
}

/** Hard requirements that block Continue / submit. */
function validateStep(step: StepKey, data: WizardData): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'paper' && !data.title.trim()) {
        errors.title = 'Give the paper a title.';
    }
    if (step === 'voting') {
        data.follow_up_actions.forEach((action, index) => {
            const hasContent =
                action.title.trim() !== '' ||
                action.assigned_to != null ||
                action.due_date !== '';
            if (!hasContent) return;
            if (!action.title.trim()) {
                errors[`follow_up_actions.${index}.title`] =
                    'Describe the action.';
            }
            if (action.assigned_to == null) {
                errors[`follow_up_actions.${index}.assigned_to`] =
                    'Choose who is accountable.';
            }
            if (!action.due_date) {
                errors[`follow_up_actions.${index}.due_date`] =
                    'Set a due date.';
            }
        });
    }
    return errors;
}

/** Publication readiness (drafts may still be saved). Mirrors validateForPublication. */
function publicationIssues(
    data: WizardData,
): { step: StepKey; message: string }[] {
    const issues: { step: StepKey; message: string }[] = [];
    if (!data.title.trim())
        issues.push({ step: 'paper', message: 'Title is required.' });
    if (!data.context.trim())
        issues.push({
            step: 'paper',
            message: 'Background context and rationale are required.',
        });
    if (!data.exact_motion.trim())
        issues.push({
            step: 'motion',
            message: 'Exact motion wording is required.',
        });
    if (!data.purpose)
        issues.push({ step: 'motion', message: 'Choose the paper purpose.' });
    if (data.purpose === 'decision') {
        const validOptions = data.options.filter((o) => o.label.trim() !== '');
        if (validOptions.length < 2 && !data.single_option_reason.trim()) {
            issues.push({
                step: 'options',
                message:
                    'Evaluate at least two options, or explain why only one applies.',
            });
        }
        if (!data.recommendation.trim()) {
            issues.push({
                step: 'options',
                message: 'A management recommendation is required.',
            });
        }
    }
    if (data.has_cost && !data.cost_amount.trim()) {
        issues.push({
            step: 'implications',
            message: 'Enter the financial cost amount.',
        });
    }
    if (!data.service_user_implications.trim()) {
        issues.push({
            step: 'implications',
            message: 'Service-user and safety implications are required.',
        });
    }
    if (!data.risk_equity_implications.trim()) {
        issues.push({
            step: 'implications',
            message: 'Risk and equity implications are required.',
        });
    }
    return issues;
}

function completeness(data: WizardData): number {
    const checks = [
        data.title.trim() !== '',
        data.context.trim() !== '',
        data.exact_motion.trim() !== '',
        data.purpose !== '',
        data.decision_type !== '',
        data.purpose !== 'decision' ||
            data.options.filter((o) => o.label.trim() !== '').length >= 2 ||
            data.single_option_reason.trim() !== '',
        data.purpose !== 'decision' || data.recommendation.trim() !== '',
        !data.has_cost || data.cost_amount.trim() !== '',
        data.service_user_implications.trim() !== '',
        data.risk_equity_implications.trim() !== '',
        data.type !== '',
    ];
    return Math.round((checks.filter(Boolean).length / checks.length) * 100);
}

// ── Wizard ────────────────────────────────────────────────────────────────

export interface ResolutionWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingOption[];
    committees?: CommitteeOption[];
    users?: UserOption[];
    /** Preselected meeting for a new paper. */
    meetingId?: number | string | null;
    /** Opened from a meeting: the meeting is shown as locked context. */
    lockMeeting?: boolean;
    /** Edit mode — the same wizard, prefilled. */
    resolution?: ResolutionRecord | null;
    /** Records the author may bind; omit where authority is not editable. */
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
    /** The paper's current binding(s) when editing. */
    authorityBindings?: AuthorityBinding[];
    /** Whether "Publish for voting" is offered (server re-checks). */
    canPublish?: boolean;
    onCreated?: () => void;
}

export function ResolutionWizardDialog(props: ResolutionWizardDialogProps) {
    if (!props.isOpen) return null;
    return <ResolutionWizardBody {...props} />;
}

// ── Declare a conflict of interest (simple dialog) ──────────────────────────

const CONFLICT_TYPES: TileDef[] = [
    {
        key: 'material',
        label: 'Material interest',
        description: 'A personal or financial interest.',
        icon: DollarSign,
    },
    {
        key: 'related',
        label: 'Related party',
        description: 'A related-party transaction.',
        icon: Users,
    },
    {
        key: 'prejudicial',
        label: 'Bias or loyalty',
        description: 'Prejudicial bias or a loyalty conflict.',
        icon: Scale,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Another perceived conflict.',
        icon: AlertTriangle,
    },
];

export function DeclareConflictDialog({
    isOpen,
    onClose,
    resolutionId,
    reference,
}: {
    isOpen: boolean;
    onClose: () => void;
    resolutionId: number;
    reference: string;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {isOpen ? (
                    <DeclareConflictBody
                        onClose={onClose}
                        resolutionId={resolutionId}
                        reference={reference}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DeclareConflictBody({
    onClose,
    resolutionId,
    reference,
}: {
    onClose: () => void;
    resolutionId: number;
    reference: string;
}) {
    const form = useForm({
        type: 'material',
        description: '',
        withdraw_from_voting: true,
        withdraw_from_discussion: false,
    });
    const tooShort = form.data.description.trim().length < 20;

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        if (tooShort) return;
        form.post(`/governance/resolutions/${resolutionId}/conflict`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                    Declare a conflict of interest
                </DialogTitle>
                <DialogDescription>
                    Formally declare an interest in {reference}. Withdrawing
                    from voting excludes your seat without recording an
                    abstention.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                <Field
                    label="Nature of conflict"
                    required
                    error={form.errors.type}
                >
                    <TilePicker
                        value={form.data.type}
                        onChange={(value) => form.setData('type', value)}
                        options={CONFLICT_TYPES}
                    />
                </Field>
                <Field
                    label="Affected matter & detail"
                    required
                    hint="At least 20 characters"
                    error={
                        form.errors.description ??
                        (form.data.description.length > 0 && tooShort
                            ? `Add a little more detail (${form.data.description.trim().length}/20).`
                            : undefined)
                    }
                >
                    <Textarea
                        id="conflict-description"
                        rows={4}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="Describe your interest and the decisions it affects."
                    />
                </Field>
                <div className="grid gap-3">
                    <Label className="flex items-start gap-2.5 font-normal">
                        <Checkbox
                            checked={form.data.withdraw_from_voting}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'withdraw_from_voting',
                                    checked === true,
                                )
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">
                                Withdraw from voting
                            </span>
                            <span className="text-caption block">
                                Excludes your seat from participation without
                                recording an abstention.
                            </span>
                        </span>
                    </Label>
                    <Label className="flex items-start gap-2.5 font-normal">
                        <Checkbox
                            checked={form.data.withdraw_from_discussion}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'withdraw_from_discussion',
                                    checked === true,
                                )
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">
                                Withdraw from discussion
                            </span>
                            <span className="text-caption block">
                                Leave the room or meeting during deliberation.
                            </span>
                        </span>
                    </Label>
                </div>
                <InfoCard icon={Scale}>
                    Declaring a conflict and withdrawing does not reduce the
                    quorum denominator. Quorum requires enough non-conflicted
                    members to take part.
                </InfoCard>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || tooShort}>
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    Record declaration
                </Button>
            </DialogFooter>
        </form>
    );
}

/** Backwards-compatible name used by meeting contexts. */
export function NewResolutionDialog(props: ResolutionWizardDialogProps) {
    return <ResolutionWizardDialog {...props} />;
}

function ResolutionWizardBody({
    onClose,
    meetings,
    committees = [],
    users = [],
    meetingId,
    lockMeeting = false,
    resolution = null,
    authoritySubjects = null,
    authoritySubjectGroups = [],
    authorityBindings = [],
    canPublish = true,
    onCreated,
}: ResolutionWizardDialogProps) {
    const isEdit = resolution != null;
    const [initial] = useState(() =>
        initialData(resolution, meetingId, authorityBindings),
    );
    const form = useForm<WizardData>(initial);
    const data = form.data;
    const serverErrors = form.errors as Record<string, string | undefined>;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [confirmClose, setConfirmClose] = useState(false);
    const [submitting, setSubmitting] = useState<'draft' | 'publish' | null>(
        null,
    );
    const [done, setDone] = useState<{
        message?: string;
        warning?: string;
    } | null>(null);

    const step = RESOLUTION_WIZARD_STEPS[stepIndex]!;
    const isReview = step.key === 'review';
    const errorFor = (key: string) => clientErrors[key] ?? serverErrors[key];

    const set = <K extends keyof WizardData>(key: K, value: WizardData[K]) => {
        form.setData((current) => ({ ...current, [key]: value }));
        if (clientErrors[key as string]) {
            setClientErrors((current) => {
                const nextErrors = { ...current };
                delete nextErrors[key as string];
                return nextErrors;
            });
        }
    };

    // Authority picker: render whatever groups the server describes.
    const showAuthority = authoritySubjects != null;
    const authorityGroups = useMemo(
        () =>
            authoritySubjectGroups
                .map((group) => ({
                    ...group,
                    options: authoritySubjects?.[group.key] ?? [],
                }))
                .filter((group) => group.options.length > 0),
        [authoritySubjectGroups, authoritySubjects],
    );
    const currentBinding = authorityBindings[0] ?? null;
    const currentBindingListed =
        currentBinding != null &&
        authorityGroups.some((group) =>
            group.options.some(
                (option) =>
                    bindingKey(group.subject_type, option.id) ===
                    bindingKey(
                        currentBinding.subject_type,
                        currentBinding.subject_id,
                    ),
            ),
        );
    const authorityLabel = (key: string): string | null => {
        if (key === NONE) return null;
        for (const group of authorityGroups) {
            const match = group.options.find(
                (option) => bindingKey(group.subject_type, option.id) === key,
            );
            if (match) return `${group.label}: ${match.label}`;
        }
        if (
            currentBinding &&
            key ===
                bindingKey(
                    currentBinding.subject_type,
                    currentBinding.subject_id,
                )
        ) {
            return `${currentBinding.subject_type_label}: ${currentBinding.subject_label}`;
        }
        return key;
    };

    const meetingLabel = (id: string) => {
        if (id === NONE) return null;
        const meeting = meetings.find((m) => String(m.id) === id);
        if (!meeting) return resolution?.meeting?.title ?? `Meeting #${id}`;
        return meeting.scheduled_at
            ? `${meeting.title} (${formatDateLong(meeting.scheduled_at)})`
            : meeting.title;
    };
    const committeeLabel = (id: string) =>
        id === NONE
            ? 'Whole board'
            : (committees.find((c) => String(c.id) === id)?.name ??
              resolution?.committee?.name ??
              `Committee #${id}`);
    const lockedMeeting = lockMeeting && data.meeting_id !== NONE;

    const issues = publicationIssues(data);
    const isPublishReady = issues.length === 0;
    const pct = completeness(data);

    const goTo = (index: number) => {
        setStepIndex(
            Math.max(0, Math.min(RESOLUTION_WIZARD_STEPS.length - 1, index)),
        );
    };

    const next = () => {
        const errors = validateStep(step.key, data);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        goTo(stepIndex + 1);
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = (publish: boolean) => {
        const allErrors = {
            ...validateStep('paper', data),
            ...validateStep('voting', data),
        };
        if (Object.keys(allErrors).length > 0) {
            setClientErrors(allErrors);
            const target = firstErrorStep(allErrors, FIELD_STEPS, 'review');
            if (target) goTo(STEP_INDEX[target]);
            return;
        }
        setClientErrors({});

        const initialPayload = toPayload(initial);
        form.transform((current) => {
            const payload = toPayload(current);
            if (!showAuthority) delete payload.authority_binding;

            if (isEdit && resolution) {
                // Send only what changed: untouched fields (and the paper's
                // current approval binding) stay exactly as recorded.
                const changed: Record<string, unknown> = {};
                for (const [key, value] of Object.entries(payload)) {
                    if (
                        JSON.stringify(value) !==
                        JSON.stringify(initialPayload[key])
                    ) {
                        changed[key] = value;
                    }
                }
                return {
                    ...changed,
                    expected_version: resolution.version_number ?? 1,
                } as unknown as WizardData;
            }

            if (payload.authority_binding === null)
                delete payload.authority_binding;
            return {
                ...payload,
                publish_now: publish,
                _modal: true,
            } as unknown as WizardData;
        });

        setSubmitting(publish ? 'publish' : 'draft');
        const visit = {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) => {
                const target = firstErrorStep(errors, FIELD_STEPS, 'review');
                if (target) goTo(STEP_INDEX[target]);
            },
            onSuccess: (page: unknown) => {
                const flash = (
                    page as {
                        props?: {
                            flash?: { success?: string; error?: string };
                        };
                    }
                )?.props?.flash;
                // The paper is saved; a flash error here means it could not
                // also be published, which the success pane says plainly.
                setDone(
                    pageHasFlashError(page)
                        ? { warning: flash?.error }
                        : { message: flash?.success },
                );
                onCreated?.();
            },
            onFinish: () => setSubmitting(null),
        };

        if (isEdit && resolution) {
            form.put(`/governance/resolutions/${resolution.id}`, visit);
        } else {
            form.post(storeResolution.url(), visit);
        }
    };

    const startAnother = () => {
        form.clearErrors();
        form.setData(initialData(null, lockMeeting ? meetingId : null, []));
        setClientErrors({});
        setStepIndex(0);
        setDone(null);
    };

    const success = done ? (
        <WizardSuccessPane
            title={
                done.warning
                    ? 'Saved as a draft'
                    : isEdit
                      ? 'Decision paper updated'
                      : 'Decision paper created'
            }
            blurb={
                <>
                    {done.warning ?? done.message ?? (
                        <>
                            <strong>{data.title}</strong> has been saved.
                        </>
                    )}
                    {!isEdit && !done.warning ? (
                        <span className="mt-1 block">
                            Open it from the register to attach supporting
                            documents.
                        </span>
                    ) : null}
                </>
            }
            actions={
                isEdit ? (
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={startAnother}
                        >
                            <Plus className="h-4 w-4" /> Author another
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Done
                        </Button>
                    </>
                )
            }
        />
    ) : undefined;

    const processing = form.processing || submitting !== null;

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={
                    isEdit ? 'Edit decision paper' : 'Author a decision paper'
                }
                description="Structured decision paper authoring for informed, accountable board decisions."
                railIcon={Gavel}
                railTitle={isEdit ? 'Edit paper' : 'New paper'}
                railSub={
                    isEdit
                        ? `${resolution?.resolution_reference ?? 'Draft'} · v${resolution?.version_number ?? 1}`
                        : lockedMeeting
                          ? 'Meeting paper'
                          : 'Board decision'
                }
                steps={RESOLUTION_WIZARD_STEPS}
                stepIndex={stepIndex}
                onStepClick={goTo}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => goTo(stepIndex - 1)}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {!isReview ? (
                            <Button type="button" onClick={next}>
                                Continue
                            </Button>
                        ) : isEdit ? (
                            <Button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : null}
                                Save changes
                            </Button>
                        ) : canPublish ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => submit(false)}
                                    disabled={processing}
                                >
                                    {submitting === 'draft' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : null}
                                    Save draft
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => submit(true)}
                                    disabled={processing || !isPublishReady}
                                >
                                    {submitting === 'publish' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : null}
                                    Publish for voting
                                </Button>
                            </>
                        ) : (
                            <Button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : null}
                                Save draft
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={step.key}>
                    <div className="p-6">
                        {step.key === 'paper' ? (
                            <>
                                <StepHead
                                    icon={FileText}
                                    title="Paper & meeting"
                                    blurb="Name the paper, place it on a meeting and explain why it is before the board."
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Paper title"
                                        required
                                        error={errorFor('title')}
                                        span
                                    >
                                        <Input
                                            id="resolution-title"
                                            value={data.title}
                                            onChange={(e) =>
                                                set('title', e.target.value)
                                            }
                                            placeholder="e.g. Approval of the 2026/27 strategic plan"
                                        />
                                    </Field>
                                    <Field
                                        label="Meeting"
                                        error={
                                            errorFor('meeting_id') ??
                                            errorFor('governance_meeting_id')
                                        }
                                    >
                                        {lockedMeeting ? (
                                            <InfoCard icon={Gavel}>
                                                <span className="font-medium">
                                                    {meetingLabel(
                                                        data.meeting_id,
                                                    )}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    Locked from the meeting you
                                                    opened.
                                                </span>
                                            </InfoCard>
                                        ) : (
                                            <Select
                                                value={data.meeting_id}
                                                onValueChange={(value) =>
                                                    set('meeting_id', value)
                                                }
                                            >
                                                <SelectTrigger
                                                    id="resolution-meeting"
                                                    aria-label="Meeting"
                                                >
                                                    <SelectValue placeholder="No meeting" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        Standalone paper (no
                                                        meeting)
                                                    </SelectItem>
                                                    {meetings.map((m) => (
                                                        <SelectItem
                                                            key={m.id}
                                                            value={String(m.id)}
                                                        >
                                                            {meetingLabel(
                                                                String(m.id),
                                                            )}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}
                                    </Field>
                                    <Field
                                        label="Board committee"
                                        error={errorFor('board_committee_id')}
                                    >
                                        <Select
                                            value={data.board_committee_id}
                                            onValueChange={(value) =>
                                                set('board_committee_id', value)
                                            }
                                        >
                                            <SelectTrigger
                                                id="resolution-committee"
                                                aria-label="Board committee"
                                            >
                                                <SelectValue placeholder="Whole board" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    Whole board (no committee)
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
                                    </Field>
                                    <Field
                                        label="Background & rationale"
                                        hint="Why now?"
                                        error={
                                            errorFor('context') ??
                                            errorFor('description')
                                        }
                                        span
                                    >
                                        <Textarea
                                            id="resolution-context"
                                            rows={5}
                                            value={data.context}
                                            onChange={(e) =>
                                                set('context', e.target.value)
                                            }
                                            placeholder="Background, drivers for change, previous decisions and why this needs a board determination."
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {step.key === 'motion' ? (
                            <>
                                <StepHead
                                    icon={Gavel}
                                    title="Motion & approval"
                                    blurb="The exact words the board votes on, and the one record a carried motion approves."
                                />
                                <div className="grid gap-4">
                                    <Field
                                        label="Exact motion"
                                        error={errorFor('exact_motion')}
                                    >
                                        <Textarea
                                            id="resolution-motion"
                                            rows={3}
                                            value={data.exact_motion}
                                            onChange={(e) =>
                                                set(
                                                    'exact_motion',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="That the Board resolves to: (1) approve … (2) authorise the CEO to …"
                                        />
                                    </Field>
                                    <Field
                                        label="Purpose"
                                        error={errorFor('purpose')}
                                    >
                                        <TilePicker
                                            cols={3}
                                            value={data.purpose}
                                            onChange={(value) =>
                                                set('purpose', value)
                                            }
                                            options={PURPOSES}
                                        />
                                    </Field>
                                    <Field
                                        label="Decision category"
                                        error={errorFor('decision_type')}
                                    >
                                        <TilePicker
                                            cols={3}
                                            value={data.decision_type}
                                            onChange={(value) =>
                                                set('decision_type', value)
                                            }
                                            options={DECISION_CATEGORIES}
                                        />
                                    </Field>
                                    {showAuthority ? (
                                        <Field
                                            label="Record this paper approves"
                                            error={
                                                errorFor('authority_binding') ??
                                                errorFor(
                                                    'authority_binding.subject_type',
                                                ) ??
                                                errorFor(
                                                    'authority_binding.subject_id',
                                                )
                                            }
                                        >
                                            <Select
                                                value={
                                                    data.authority_binding_key
                                                }
                                                onValueChange={(value) =>
                                                    set(
                                                        'authority_binding_key',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="resolution-authority"
                                                    aria-label="Record this paper approves"
                                                >
                                                    <SelectValue placeholder="No specific record" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        No specific record
                                                    </SelectItem>
                                                    {currentBinding &&
                                                    !currentBindingListed ? (
                                                        <SelectGroup>
                                                            <SelectLabel>
                                                                Currently bound
                                                            </SelectLabel>
                                                            <SelectItem
                                                                value={bindingKey(
                                                                    currentBinding.subject_type,
                                                                    currentBinding.subject_id,
                                                                )}
                                                            >
                                                                {
                                                                    currentBinding.subject_type_label
                                                                }
                                                                :{' '}
                                                                {
                                                                    currentBinding.subject_label
                                                                }
                                                            </SelectItem>
                                                        </SelectGroup>
                                                    ) : null}
                                                    {authorityGroups.map(
                                                        (group) => (
                                                            <SelectGroup
                                                                key={group.key}
                                                            >
                                                                <SelectLabel>
                                                                    {
                                                                        group.label
                                                                    }
                                                                </SelectLabel>
                                                                {group.options.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={bindingKey(
                                                                                group.subject_type,
                                                                                option.id,
                                                                            )}
                                                                            value={bindingKey(
                                                                                group.subject_type,
                                                                                option.id,
                                                                            )}
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectGroup>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-caption mt-1.5">
                                                A carried resolution can only
                                                approve the exact record and
                                                revision selected here. Choose
                                                “No specific record” to remove a
                                                binding.
                                            </p>
                                        </Field>
                                    ) : null}
                                </div>
                            </>
                        ) : null}

                        {step.key === 'options' ? (
                            <>
                                <StepHead
                                    icon={Scale}
                                    title="Options & recommendation"
                                    blurb="Consequential decisions evaluate real alternatives, or explain why only one path exists."
                                />
                                <div className="grid gap-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-subtle">
                                            {data.options.length} option
                                            {data.options.length === 1
                                                ? ''
                                                : 's'}{' '}
                                            evaluated
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                set('options', [
                                                    ...data.options,
                                                    {
                                                        label: `Option ${data.options.length + 1}`,
                                                        description: '',
                                                        benefits: '',
                                                        drawbacks: '',
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" /> Add
                                            option
                                        </Button>
                                    </div>
                                    {data.options.map((option, index) => {
                                        const update = (
                                            field: keyof OptionRow,
                                            value: string,
                                        ) =>
                                            set(
                                                'options',
                                                data.options.map((row, i) =>
                                                    i === index
                                                        ? {
                                                              ...row,
                                                              [field]: value,
                                                          }
                                                        : row,
                                                ),
                                            );
                                        return (
                                            <Card
                                                key={index}
                                                className="gap-3 p-4"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        aria-label={`Option ${index + 1} title`}
                                                        value={option.label}
                                                        onChange={(e) =>
                                                            update(
                                                                'label',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder={`Option ${index + 1} title`}
                                                        className="font-medium"
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Remove option ${index + 1}`}
                                                        onClick={() =>
                                                            set(
                                                                'options',
                                                                data.options.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-status-critical" />
                                                    </Button>
                                                </div>
                                                <Textarea
                                                    aria-label={`Option ${index + 1} description`}
                                                    rows={2}
                                                    value={option.description}
                                                    onChange={(e) =>
                                                        update(
                                                            'description',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="What this course of action involves…"
                                                />
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <Textarea
                                                        aria-label={`Option ${index + 1} benefits`}
                                                        rows={2}
                                                        value={option.benefits}
                                                        onChange={(e) =>
                                                            update(
                                                                'benefits',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Benefits and opportunities"
                                                    />
                                                    <Textarea
                                                        aria-label={`Option ${index + 1} drawbacks`}
                                                        rows={2}
                                                        value={option.drawbacks}
                                                        onChange={(e) =>
                                                            update(
                                                                'drawbacks',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Drawbacks, costs and risks"
                                                    />
                                                </div>
                                            </Card>
                                        );
                                    })}
                                    {errorFor('options') ? (
                                        <p className="text-xs text-status-critical">
                                            {errorFor('options')}
                                        </p>
                                    ) : null}
                                    {data.options.filter(
                                        (o) => o.label.trim() !== '',
                                    ).length < 2 ? (
                                        <Field
                                            label="Single option justification"
                                            error={errorFor(
                                                'single_option_reason',
                                            )}
                                        >
                                            <Textarea
                                                id="resolution-single-option"
                                                rows={2}
                                                value={
                                                    data.single_option_reason
                                                }
                                                onChange={(e) =>
                                                    set(
                                                        'single_option_reason',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Why only one option is feasible (e.g. statutory mandate, sole provider)."
                                            />
                                        </Field>
                                    ) : null}
                                    <Field
                                        label="Management recommendation & rationale"
                                        error={errorFor('recommendation')}
                                    >
                                        <Textarea
                                            id="resolution-recommendation"
                                            rows={3}
                                            value={data.recommendation}
                                            onChange={(e) =>
                                                set(
                                                    'recommendation',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Which option management recommends, and why."
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {step.key === 'implications' ? (
                            <>
                                <StepHead
                                    icon={ShieldAlert}
                                    title="Implications"
                                    blurb="Account honestly for cost, service-user safety, risk and equity (including Te Tiriti)."
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Financial cost"
                                        error={errorFor('cost_impact')}
                                        span
                                    >
                                        <TilePicker
                                            value={
                                                data.has_cost ? 'cost' : 'none'
                                            }
                                            onChange={(value) =>
                                                set(
                                                    'has_cost',
                                                    value === 'cost',
                                                )
                                            }
                                            options={[
                                                {
                                                    key: 'none',
                                                    label: 'No direct cost',
                                                    description:
                                                        'Explicitly confirmed: no capital or operating spend.',
                                                    icon: CheckCircle2,
                                                },
                                                {
                                                    key: 'cost',
                                                    label: 'Has budget impact',
                                                    description:
                                                        'Spend, funding or a budget adjustment.',
                                                    icon: DollarSign,
                                                },
                                            ]}
                                        />
                                    </Field>
                                    {data.has_cost ? (
                                        <>
                                            <Field
                                                label="Estimated amount"
                                                required
                                            >
                                                <Input
                                                    id="resolution-cost-amount"
                                                    inputMode="decimal"
                                                    value={data.cost_amount}
                                                    onChange={(e) =>
                                                        set(
                                                            'cost_amount',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. 85000"
                                                />
                                            </Field>
                                            <Field label="Currency">
                                                <Select
                                                    value={data.cost_currency}
                                                    onValueChange={(value) =>
                                                        set(
                                                            'cost_currency',
                                                            value,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="resolution-cost-currency"
                                                        aria-label="Currency"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {CURRENCIES.map(
                                                            (currency) => (
                                                                <SelectItem
                                                                    key={
                                                                        currency
                                                                    }
                                                                    value={
                                                                        currency
                                                                    }
                                                                >
                                                                    {currency}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </Field>
                                            <Field
                                                label="Budget source / fund"
                                                span
                                            >
                                                <Input
                                                    id="resolution-cost-source"
                                                    value={data.cost_source}
                                                    onChange={(e) =>
                                                        set(
                                                            'cost_source',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. OPEX FY27 regional services"
                                                />
                                            </Field>
                                        </>
                                    ) : null}
                                    <Field
                                        label="Service-user & safety implications"
                                        error={errorFor(
                                            'service_user_implications',
                                        )}
                                        span
                                    >
                                        <Textarea
                                            id="resolution-service-user"
                                            rows={3}
                                            value={
                                                data.service_user_implications
                                            }
                                            onChange={(e) =>
                                                set(
                                                    'service_user_implications',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Effects on the people we support, service quality and safeguarding."
                                        />
                                    </Field>
                                    <Field
                                        label="Risk, equity & Te Tiriti implications"
                                        error={errorFor(
                                            'risk_equity_implications',
                                        )}
                                        span
                                    >
                                        <Textarea
                                            id="resolution-risk-equity"
                                            rows={3}
                                            value={
                                                data.risk_equity_implications
                                            }
                                            onChange={(e) =>
                                                set(
                                                    'risk_equity_implications',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Key risks and controls, equity impacts and Te Tiriti o Waitangi obligations."
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {step.key === 'voting' ? (
                            <>
                                <StepHead
                                    icon={Vote}
                                    title="Voting & follow-up actions"
                                    blurb="Set the threshold and deadline, and the accountable actions a carried decision creates."
                                />
                                <div className="grid gap-4">
                                    <Field
                                        label="Voting threshold"
                                        error={
                                            errorFor('type') ??
                                            errorFor('voting_threshold')
                                        }
                                    >
                                        <TilePicker
                                            cols={3}
                                            value={data.type}
                                            onChange={(value) =>
                                                set('type', value)
                                            }
                                            options={RESOLUTION_TYPES}
                                        />
                                    </Field>
                                    <Field
                                        label="Voting deadline"
                                        hint="Leave blank to decide at the meeting"
                                        error={errorFor('voting_deadline')}
                                    >
                                        <Input
                                            id="resolution-deadline"
                                            type="datetime-local"
                                            value={data.voting_deadline}
                                            onChange={(e) =>
                                                set(
                                                    'voting_deadline',
                                                    e.target.value,
                                                )
                                            }
                                            className="sm:max-w-xs"
                                        />
                                    </Field>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold">
                                                Follow-up actions
                                            </p>
                                            <p className="text-caption">
                                                Created as accountable actions
                                                when the motion carries.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                set('follow_up_actions', [
                                                    ...data.follow_up_actions,
                                                    {
                                                        title: '',
                                                        assignee_name: '',
                                                        assigned_to: null,
                                                        due_date: '',
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" /> Add
                                            action
                                        </Button>
                                    </div>
                                    {data.follow_up_actions.length === 0 ? (
                                        <p className="text-subtle">
                                            No follow-up actions yet.
                                        </p>
                                    ) : null}
                                    {data.follow_up_actions.map(
                                        (action, index) => {
                                            const update = (
                                                patch: Partial<FollowUpRow>,
                                            ) => {
                                                set(
                                                    'follow_up_actions',
                                                    data.follow_up_actions.map(
                                                        (row, i) =>
                                                            i === index
                                                                ? {
                                                                      ...row,
                                                                      ...patch,
                                                                  }
                                                                : row,
                                                    ),
                                                );
                                                setClientErrors((current) =>
                                                    Object.fromEntries(
                                                        Object.entries(
                                                            current,
                                                        ).filter(
                                                            ([key]) =>
                                                                !key.startsWith(
                                                                    `follow_up_actions.${index}.`,
                                                                ),
                                                        ),
                                                    ),
                                                );
                                            };
                                            return (
                                                <Card
                                                    key={index}
                                                    className="p-4"
                                                >
                                                    <div className="grid gap-3 sm:grid-cols-[2fr_1.2fr_1fr_auto] sm:items-start">
                                                        <Field
                                                            label="Action"
                                                            required
                                                            error={errorFor(
                                                                `follow_up_actions.${index}.title`,
                                                            )}
                                                        >
                                                            <Input
                                                                value={
                                                                    action.title
                                                                }
                                                                onChange={(e) =>
                                                                    update({
                                                                        title: e
                                                                            .target
                                                                            .value,
                                                                    })
                                                                }
                                                                placeholder="e.g. Issue the revised contract"
                                                            />
                                                        </Field>
                                                        <Field
                                                            label="Accountable"
                                                            required
                                                            error={errorFor(
                                                                `follow_up_actions.${index}.assigned_to`,
                                                            )}
                                                        >
                                                            <Select
                                                                value={
                                                                    action.assigned_to !=
                                                                    null
                                                                        ? String(
                                                                              action.assigned_to,
                                                                          )
                                                                        : NONE
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) => {
                                                                    const user =
                                                                        users.find(
                                                                            (
                                                                                u,
                                                                            ) =>
                                                                                String(
                                                                                    u.id,
                                                                                ) ===
                                                                                value,
                                                                        );
                                                                    update({
                                                                        assigned_to:
                                                                            user
                                                                                ? user.id
                                                                                : null,
                                                                        assignee_name:
                                                                            user?.name ??
                                                                            '',
                                                                    });
                                                                }}
                                                            >
                                                                <SelectTrigger
                                                                    aria-label={`Action ${index + 1} accountable person`}
                                                                >
                                                                    <SelectValue placeholder="Choose a person" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem
                                                                        value={
                                                                            NONE
                                                                        }
                                                                    >
                                                                        {action.assignee_name &&
                                                                        action.assigned_to ==
                                                                            null
                                                                            ? `${action.assignee_name} (not linked)`
                                                                            : 'Choose a person'}
                                                                    </SelectItem>
                                                                    {users.map(
                                                                        (u) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    u.id
                                                                                }
                                                                                value={String(
                                                                                    u.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    u.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        </Field>
                                                        <Field
                                                            label="Due"
                                                            required
                                                            error={errorFor(
                                                                `follow_up_actions.${index}.due_date`,
                                                            )}
                                                        >
                                                            <Input
                                                                type="date"
                                                                value={
                                                                    action.due_date
                                                                }
                                                                onChange={(e) =>
                                                                    update({
                                                                        due_date:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    })
                                                                }
                                                            />
                                                        </Field>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="sm:mt-6"
                                                            aria-label={`Remove action ${index + 1}`}
                                                            onClick={() =>
                                                                set(
                                                                    'follow_up_actions',
                                                                    data.follow_up_actions.filter(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) =>
                                                                            i !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4 text-status-critical" />
                                                        </Button>
                                                    </div>
                                                </Card>
                                            );
                                        },
                                    )}
                                </div>
                            </>
                        ) : null}

                        {isReview ? (
                            <>
                                <StepHead
                                    icon={ClipboardCheck}
                                    title="Review"
                                    blurb={
                                        isEdit
                                            ? 'Check the changes before saving this draft.'
                                            : 'Check the paper. Drafts can be saved at any time; publishing opens voting.'
                                    }
                                />
                                <div className="grid gap-4">
                                    {errorFor('expected_version') ? (
                                        <InfoCard
                                            icon={AlertTriangle}
                                            tone="crit"
                                        >
                                            {errorFor('expected_version')}
                                        </InfoCard>
                                    ) : null}
                                    <InfoCard
                                        icon={
                                            isPublishReady
                                                ? CheckCircle2
                                                : AlertTriangle
                                        }
                                        tone={isPublishReady ? 'info' : 'warn'}
                                    >
                                        <span className="font-medium">
                                            {isPublishReady
                                                ? 'This paper meets the publication standard.'
                                                : 'This paper is an incomplete draft. It can be saved, but not published yet:'}
                                        </span>
                                        {!isPublishReady ? (
                                            <ul className="mt-1 list-inside list-disc">
                                                {issues.map((issue) => (
                                                    <li key={issue.message}>
                                                        {issue.message}
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </InfoCard>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <ReviewCard
                                            icon={FileText}
                                            title="Paper & meeting"
                                            onEdit={() =>
                                                goTo(STEP_INDEX.paper)
                                            }
                                        >
                                            <ReviewRow
                                                label="Title"
                                                value={data.title}
                                            />
                                            <ReviewRow
                                                label="Meeting"
                                                value={
                                                    meetingLabel(
                                                        data.meeting_id,
                                                    ) ?? 'Standalone paper'
                                                }
                                            />
                                            <ReviewRow
                                                label="Committee"
                                                value={committeeLabel(
                                                    data.board_committee_id,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Background"
                                                value={
                                                    data.context
                                                        ? `${data.context.length} characters`
                                                        : ''
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Gavel}
                                            title="Motion & approval"
                                            onEdit={() =>
                                                goTo(STEP_INDEX.motion)
                                            }
                                        >
                                            <ReviewRow
                                                label="Purpose"
                                                value={
                                                    PURPOSES.find(
                                                        (p) =>
                                                            p.key ===
                                                            data.purpose,
                                                    )?.label
                                                }
                                            />
                                            <ReviewRow
                                                label="Category"
                                                value={
                                                    DECISION_CATEGORIES.find(
                                                        (c) =>
                                                            c.key ===
                                                            data.decision_type,
                                                    )?.label ??
                                                    data.decision_type
                                                }
                                            />
                                            <ReviewRow
                                                label="Motion"
                                                value={
                                                    data.exact_motion
                                                        ? 'Provided'
                                                        : ''
                                                }
                                            />
                                            {showAuthority ? (
                                                <ReviewRow
                                                    label="Approves"
                                                    value={
                                                        authorityLabel(
                                                            data.authority_binding_key,
                                                        ) ??
                                                        'No specific record'
                                                    }
                                                />
                                            ) : null}
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Scale}
                                            title="Options"
                                            onEdit={() =>
                                                goTo(STEP_INDEX.options)
                                            }
                                        >
                                            <ReviewRow
                                                label="Options evaluated"
                                                value={String(
                                                    data.options.filter(
                                                        (o) =>
                                                            o.label.trim() !==
                                                            '',
                                                    ).length,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Single-option reason"
                                                value={
                                                    data.single_option_reason
                                                        ? 'Provided'
                                                        : ''
                                                }
                                            />
                                            <ReviewRow
                                                label="Recommendation"
                                                value={
                                                    data.recommendation
                                                        ? 'Provided'
                                                        : ''
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={ShieldAlert}
                                            title="Implications"
                                            onEdit={() =>
                                                goTo(STEP_INDEX.implications)
                                            }
                                        >
                                            <ReviewRow
                                                label="Cost"
                                                value={
                                                    data.has_cost
                                                        ? `${data.cost_currency} ${data.cost_amount || '—'}`
                                                        : 'No direct cost'
                                                }
                                            />
                                            <ReviewRow
                                                label="Service-user & safety"
                                                value={
                                                    data.service_user_implications
                                                        ? 'Provided'
                                                        : ''
                                                }
                                            />
                                            <ReviewRow
                                                label="Risk & equity"
                                                value={
                                                    data.risk_equity_implications
                                                        ? 'Provided'
                                                        : ''
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={ListChecks}
                                            title="Voting & actions"
                                            onEdit={() =>
                                                goTo(STEP_INDEX.voting)
                                            }
                                            span
                                        >
                                            <ReviewRow
                                                label="Threshold"
                                                value={
                                                    RESOLUTION_TYPES.find(
                                                        (t) =>
                                                            t.key === data.type,
                                                    )?.label
                                                }
                                            />
                                            <ReviewRow
                                                label="Deadline"
                                                value={
                                                    data.voting_deadline
                                                        ? formatDateTimeLong(
                                                              data.voting_deadline,
                                                          )
                                                        : 'Decided at the meeting'
                                                }
                                            />
                                            <ReviewRow
                                                label="Follow-up actions"
                                                value={String(
                                                    data.follow_up_actions.filter(
                                                        (a) =>
                                                            a.title.trim() !==
                                                            '',
                                                    ).length,
                                                )}
                                            />
                                        </ReviewCard>
                                    </div>
                                    {showAuthority &&
                                    data.authority_binding_key !== NONE ? (
                                        <InfoCard icon={Link2}>
                                            The binding records the selected
                                            record&apos;s current revision. If
                                            that record changes before the
                                            decision is applied, the approval
                                            will not apply to the changed
                                            version.
                                        </InfoCard>
                                    ) : null}
                                </div>
                            </>
                        ) : null}
                    </div>
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your unsaved changes to this decision paper will be lost.'
                        : 'This decision paper has not been saved and will be lost.'
                }
            />
        </>
    );
}
