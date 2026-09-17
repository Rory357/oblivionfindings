import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { formatDateLong, toDatetimeLocal } from '@/lib/datetime';
import {
    decisionTypeLabel,
    formatNzd,
    refSuffix,
    resolutionPurposeLabel,
    votingThresholdLabel,
} from '@/lib/governance-labels';
import { store as storeResolution } from '@/routes/governance/resolutions';
import { Link, useForm } from '@inertiajs/react';
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
import { useMemo, useState } from 'react';

// ── Registries (tile pickers) ───────────────────────────────────────────────

type ResolutionTypeKey =
    | 'ordinary'
    | 'special'
    | 'three_quarters'
    | 'unanimous';

interface TileDef<K extends string = string> {
    key: K;
    label: string;
    description: string;
    icon: LucideIcon;
    accent?: string;
}

/**
 * How a resolution passes. Labels come from governance-labels and match
 * Resolution::determineOutcome(): `special` is stored as `two_thirds`
 * (at least two-thirds of the For and Against votes are For).
 */
export const RESOLUTION_TYPES: TileDef<ResolutionTypeKey>[] = [
    {
        key: 'ordinary',
        label: votingThresholdLabel('ordinary'),
        description:
            "Passes if more members vote For than Against. Abstentions don't count.",
        icon: Vote,
        accent: 'text-status-info',
    },
    {
        key: 'special',
        label: votingThresholdLabel('special'),
        description:
            "Passes if at least two-thirds of the For and Against votes are For. Abstentions don't count.",
        icon: ScrollText,
        accent: 'text-status-warning',
    },
    {
        key: 'three_quarters',
        label: votingThresholdLabel('three_quarters'),
        description:
            "Passes if at least three-quarters of the For and Against votes are For — the usual level for changing a trust deed or constitution. Abstentions don't count.",
        icon: ScrollText,
        accent: 'text-status-critical',
    },
    {
        key: 'unanimous',
        label: votingThresholdLabel('unanimous'),
        description:
            "Passes only if every voting member votes For. Abstaining or stepping aside means it can't pass.",
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
        label: resolutionPurposeLabel('decision'),
        description: 'The board votes on it.',
        icon: Gavel,
    },
    {
        key: 'discussion',
        label: resolutionPurposeLabel('discussion'),
        description: 'The board talks it through — no vote.',
        icon: MessageSquare,
    },
    {
        key: 'information',
        label: resolutionPurposeLabel('information'),
        description: 'For the board to read and note — no vote.',
        icon: BookOpen,
    },
];

const DECISION_CATEGORIES: TileDef[] = [
    {
        key: 'strategic',
        label: decisionTypeLabel('strategic'),
        description: 'Direction and priorities.',
        icon: TrendingUp,
    },
    {
        key: 'financial',
        label: decisionTypeLabel('financial'),
        description: 'Budget, spending and funding.',
        icon: DollarSign,
    },
    {
        key: 'policy',
        label: decisionTypeLabel('policy'),
        description: 'Policies and how we work.',
        icon: ScrollText,
    },
    {
        key: 'operational',
        label: decisionTypeLabel('operational'),
        description: 'Day-to-day services, risk and safety.',
        icon: Briefcase,
    },
    {
        key: 'statutory',
        label: decisionTypeLabel('statutory'),
        description: 'Something the law requires.',
        icon: Landmark,
    },
    {
        key: 'governance',
        label: decisionTypeLabel('governance'),
        description: 'The board, its rules and its documents.',
        icon: Scale,
    },
];

const NONE = '__none';

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/**
 * "20 September 2026, 5:00 pm" for a datetime-local wall time. The value is
 * already New Zealand time, so it is never converted through the browser's
 * own timezone.
 */
export function formatWallTime(value: string | null | undefined): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value ?? '');
    if (!match) return value ?? '';
    const [, year, month, day, hours, minutes] = match;
    const hour = Number(hours);
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${Number(day)} ${MONTHS[Number(month) - 1]} ${year}, ${hour12}:${minutes} ${hour >= 12 ? 'pm' : 'am'}`;
}

function excerpt(text: string | null | undefined, max = 110): string {
    const clean = (text ?? '').replace(/\s+/g, ' ').trim();
    if (clean.length <= max) return clean;
    return `${clean.slice(0, max - 1).trimEnd()}…`;
}

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

/** One server-described group of records a resolution can approve. */
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
    subject_version?: string | null;
    bound_at?: string | null;
    consumed_at?: string | null;
}

/** The board's voting rules as they affect authoring (ResolutionController). */
export interface WizardVotingRules {
    switched_on: boolean;
    written_voting_permitted: boolean;
    written_unanimity_required: boolean;
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
        label: 'Title and meeting',
        blurb: 'Title, meeting and why it matters',
        icon: FileText,
    },
    {
        key: 'motion',
        label: 'Wording and purpose',
        blurb: 'Exact wording and what it approves',
        icon: Gavel,
    },
    {
        key: 'options',
        label: 'Options',
        blurb: 'Choices and recommendation',
        icon: Scale,
    },
    {
        key: 'implications',
        label: 'Effects',
        blurb: 'Cost, people, risk and fairness',
        icon: ShieldAlert,
    },
    {
        key: 'voting',
        label: 'How it passes',
        blurb: 'Voting rule, deadline and actions',
        icon: Vote,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: ClipboardCheck,
    },
];

/** Steps for a purpose: papers that don't go to a vote skip "How it passes". */
export function wizardStepsFor(purpose: string) {
    return purpose === 'decision' || purpose === ''
        ? RESOLUTION_WIZARD_STEPS
        : RESOLUTION_WIZARD_STEPS.filter((step) => step.key !== 'voting');
}

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
    deadline: 'voting',
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
    cost_source: string;
    service_user_implications: string;
    risk_equity_implications: string;
    type: string;
    voting_deadline: string;
    follow_up_actions: FollowUpRow[];
};

const DEFAULT_OPTIONS: OptionRow[] = [
    {
        label: 'Option 1: Go ahead as proposed',
        description: '',
        benefits: '',
        drawbacks: '',
    },
    {
        label: 'Option 2: Keep things as they are',
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
        case 'special':
            return 'special';
        case 'three_quarters':
            return 'three_quarters';
        case 'unanimous':
            return 'unanimous';
        case 'simple_majority':
        case 'ordinary':
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
        cost_source: cost?.budget_source ?? cost?.funding_source ?? '',
        service_user_implications: resolution?.service_user_implications ?? '',
        risk_equity_implications: resolution?.risk_equity_implications ?? '',
        type: resolution
            ? thresholdType(resolution.voting_threshold)
            : 'ordinary',
        // Stored as UTC; the input shows New Zealand wall time.
        voting_deadline: resolution?.deadline
            ? toDatetimeLocal(resolution.deadline)
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
                  currency: 'NZD',
                  budget_source: data.cost_source,
              }
            : {
                  has_cost: false,
                  note: 'Confirmed: no cost.',
              },
        service_user_implications: data.service_user_implications,
        risk_equity_implications: data.risk_equity_implications,
        type: data.type || null,
        // New Zealand wall time — the server converts it to UTC.
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
        errors.title = 'Give the resolution a title.';
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
                    'Say what needs doing.';
            }
            if (action.assigned_to == null) {
                errors[`follow_up_actions.${index}.assigned_to`] =
                    'Choose the person responsible.';
            }
            if (!action.due_date) {
                errors[`follow_up_actions.${index}.due_date`] =
                    'Set a due date.';
            }
        });
    }
    return errors;
}

/**
 * What still stops the paper being published (drafts can always be saved).
 * Mirrors Resolution::validateForPublication, in the same plain words.
 */
export function publicationIssues(
    data: Pick<
        WizardData,
        | 'title'
        | 'context'
        | 'exact_motion'
        | 'purpose'
        | 'options'
        | 'single_option_reason'
        | 'recommendation'
        | 'has_cost'
        | 'cost_amount'
        | 'service_user_implications'
        | 'risk_equity_implications'
        | 'meeting_id'
        | 'voting_deadline'
    >,
): { step: StepKey; message: string }[] {
    const issues: { step: StepKey; message: string }[] = [];
    if (!data.title.trim())
        issues.push({ step: 'paper', message: 'Give the resolution a title.' });
    if (!data.context.trim())
        issues.push({
            step: 'paper',
            message: 'Explain why this is before the board now.',
        });
    if (!data.exact_motion.trim())
        issues.push({
            step: 'motion',
            message:
                'Write the resolution wording — the exact words the board votes on.',
        });
    if (!data.purpose)
        issues.push({
            step: 'motion',
            message:
                'Choose whether this paper is for decision, for discussion or for information.',
        });
    if (data.purpose === 'decision') {
        const validOptions = data.options.filter((o) => o.label.trim() !== '');
        if (validOptions.length < 2 && !data.single_option_reason.trim()) {
            issues.push({
                step: 'options',
                message:
                    "Describe at least two options the board could choose, or explain why there's only one option.",
            });
        }
        if (!data.recommendation.trim()) {
            issues.push({
                step: 'options',
                message:
                    "Add management's recommendation and the reason for it.",
            });
        }
        if (data.meeting_id === NONE && !data.voting_deadline) {
            issues.push({
                step: 'voting',
                message:
                    'Set a voting deadline — votes outside a meeting (written resolutions) need one.',
            });
        }
    }
    if (data.has_cost && !data.cost_amount.trim()) {
        issues.push({
            step: 'implications',
            message: 'Say how much it will cost.',
        });
    }
    if (!data.service_user_implications.trim()) {
        issues.push({
            step: 'implications',
            message:
                'Describe the effect on the people we support and on safety.',
        });
    }
    if (!data.risk_equity_implications.trim()) {
        issues.push({
            step: 'implications',
            message: 'Describe the risks and fairness, including Te Tiriti.',
        });
    }
    return issues;
}

function completeness(data: WizardData): number {
    const isDecision = data.purpose === 'decision';
    const checks = [
        data.title.trim() !== '',
        data.context.trim() !== '',
        data.exact_motion.trim() !== '',
        data.purpose !== '',
        data.decision_type !== '',
        !isDecision ||
            data.options.filter((o) => o.label.trim() !== '').length >= 2 ||
            data.single_option_reason.trim() !== '',
        !isDecision || data.recommendation.trim() !== '',
        !data.has_cost || data.cost_amount.trim() !== '',
        data.service_user_implications.trim() !== '',
        data.risk_equity_implications.trim() !== '',
        !isDecision || data.type !== '',
        !isDecision || data.meeting_id !== NONE || data.voting_deadline !== '',
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
    /** Preselected meeting for a new resolution. */
    meetingId?: number | string | null;
    /** Opened from a meeting: the meeting is shown as locked context. */
    lockMeeting?: boolean;
    /** Edit mode — the same wizard, prefilled. */
    resolution?: ResolutionRecord | null;
    /** Records the author may link; omit where it isn't editable. */
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
    /** The resolution's current link(s) when editing. */
    authorityBindings?: AuthorityBinding[];
    /** Whether publishing is offered (server re-checks). */
    canPublish?: boolean;
    /** The board's voting rules (switched on, written votes). */
    votingRules?: WizardVotingRules | null;
    onCreated?: () => void;
}

export function ResolutionWizardDialog(props: ResolutionWizardDialogProps) {
    if (!props.isOpen) return null;
    return <ResolutionWizardBody {...props} />;
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
    votingRules = null,
    onCreated,
}: ResolutionWizardDialogProps) {
    const isEdit = resolution != null;
    const [initial] = useState(() =>
        initialData(resolution, meetingId, authorityBindings),
    );
    const form = useForm<WizardData>(initial);
    const data = form.data;
    const serverErrors = form.errors as Record<string, string | undefined>;

    const isDecision = data.purpose === 'decision';
    const isWritten = data.meeting_id === NONE;
    const steps = useMemo(() => wizardStepsFor(data.purpose), [data.purpose]);

    const [stepKey, setStepKey] = useState<StepKey>('paper');
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
        published?: boolean;
        createdId?: number | null;
    } | null>(null);

    const activeKey: StepKey = steps.some((s) => s.key === stepKey)
        ? stepKey
        : 'review';
    const stepIndex = steps.findIndex((s) => s.key === activeKey);
    const step = steps[stepIndex]!;
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

    // "What will this resolution approve?" — render whatever groups the server describes.
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
            if (match) return match.label;
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
        return null;
    };

    const meetingLabel = (id: string) => {
        if (id === NONE) return null;
        const meeting = meetings.find((m) => String(m.id) === id);
        if (!meeting) return resolution?.meeting?.title ?? 'A meeting';
        return meeting.scheduled_at
            ? `${meeting.title} (${formatDateLong(meeting.scheduled_at)})`
            : meeting.title;
    };
    const committeeLabel = (id: string) =>
        id === NONE
            ? 'Whole board'
            : (committees.find((c) => String(c.id) === id)?.name ??
              resolution?.committee?.name ??
              'A committee');
    const lockedMeeting = lockMeeting && data.meeting_id !== NONE;

    const issues = publicationIssues(data);
    const isPublishReady = issues.length === 0;
    const votingSwitchedOff = votingRules ? !votingRules.switched_on : false;
    const publishBlockedByRules = isDecision && votingSwitchedOff;
    const writtenNotAllowed =
        isDecision &&
        isWritten &&
        votingRules != null &&
        !votingRules.written_voting_permitted;
    const pct = completeness(data);

    const goTo = (index: number) => {
        const clamped = Math.max(0, Math.min(steps.length - 1, index));
        setStepKey(steps[clamped]!.key);
    };
    const goToKey = (key: StepKey) =>
        setStepKey(steps.some((s) => s.key === key) ? key : 'review');

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
            ...(isDecision ? validateStep('voting', data) : {}),
        };
        if (Object.keys(allErrors).length > 0) {
            setClientErrors(allErrors);
            const target = firstErrorStep(allErrors, FIELD_STEPS, 'review');
            if (target) goToKey(target);
            return;
        }
        setClientErrors({});

        const initialPayload = toPayload(initial);
        form.transform((current) => {
            const payload = toPayload(current);
            if (!showAuthority) delete payload.authority_binding;

            if (isEdit && resolution) {
                // Send only what changed: untouched fields (and the current
                // link to the record it approves) stay exactly as recorded.
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
                if (target) goToKey(target);
            },
            onSuccess: (page: unknown) => {
                const props = (
                    page as {
                        props?: {
                            flash?: { success?: string; error?: string };
                            created_resolution_id?: number | null;
                        };
                    }
                )?.props;
                // The resolution is saved; a flash error here means it could
                // not also be published, which the success pane says plainly.
                setDone(
                    pageHasFlashError(page)
                        ? {
                              warning: props?.flash?.error,
                              createdId: props?.created_resolution_id ?? null,
                          }
                        : {
                              message: props?.flash?.success,
                              published: publish,
                              createdId: props?.created_resolution_id ?? null,
                          },
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
        setStepKey('paper');
        setDone(null);
    };

    const successTitle = !done
        ? ''
        : done.warning
          ? 'Saved as a draft'
          : isEdit
            ? 'Resolution updated'
            : done.published
              ? isDecision
                  ? 'Voting is open'
                  : 'Published to board members'
              : 'Resolution saved';

    const success = done ? (
        <WizardSuccessPane
            title={successTitle}
            blurb={
                <>
                    {done.warning ?? done.message ?? (
                        <>
                            <strong>{data.title}</strong> has been saved.
                        </>
                    )}
                    {!isEdit && done.createdId ? (
                        <span className="mt-1 block">
                            Open the resolution to attach supporting documents.
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
                            <Plus className="h-4 w-4" /> Write another
                        </Button>
                        {done.createdId ? (
                            <Button asChild>
                                <Link
                                    href={`/governance/resolutions/${done.createdId}`}
                                >
                                    Open resolution to attach documents
                                </Link>
                            </Button>
                        ) : (
                            <Button type="button" onClick={onClose}>
                                Done
                            </Button>
                        )}
                    </>
                )
            }
        />
    ) : undefined;

    const processing = form.processing || submitting !== null;
    const publishLabel = isDecision
        ? 'Publish & open voting'
        : 'Publish to members';

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={isEdit ? 'Edit resolution' : 'New resolution'}
                description="Set out a matter for the board to decide, discuss or note."
                railIcon={Gavel}
                railTitle={isEdit ? 'Edit resolution' : 'New resolution'}
                railSub={
                    isEdit
                        ? [
                              `Version ${resolution?.version_number ?? 1}`,
                              refSuffix(resolution?.resolution_reference),
                          ]
                              .filter(Boolean)
                              .join(' · ')
                        : lockedMeeting
                          ? 'For this meeting'
                          : 'For the board'
                }
                steps={steps}
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
                                    disabled={
                                        processing ||
                                        !isPublishReady ||
                                        publishBlockedByRules
                                    }
                                >
                                    {submitting === 'publish' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : null}
                                    {publishLabel}
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
                                    title="Title and meeting"
                                    blurb="Name the resolution, choose the meeting, and explain why it's before the board."
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Title"
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
                                            placeholder="e.g. Approve the 2026/27 budget"
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
                                                    This resolution belongs to
                                                    the meeting you opened.
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
                                                    <SelectValue placeholder="Vote outside a meeting (written resolution)" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        Vote outside a meeting
                                                        (written resolution)
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
                                        {!lockedMeeting && isWritten ? (
                                            <p className="text-caption mt-1.5">
                                                Members read it and vote in the
                                                app by a deadline you set.{' '}
                                                <GovernanceTermHint term="written_resolution" />
                                            </p>
                                        ) : null}
                                    </Field>
                                    <Field
                                        label="Committee"
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
                                                aria-label="Committee"
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
                                        label="Why is this before the board?"
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
                                            placeholder="What has happened, what's needed, and why the board should look at it now."
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {step.key === 'motion' ? (
                            <>
                                <StepHead
                                    icon={Gavel}
                                    title="Wording and purpose"
                                    blurb="The exact words the board votes on, what kind of paper this is, and anything it approves."
                                />
                                <div className="grid gap-4">
                                    <Field
                                        label="Resolution wording"
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
                                            placeholder="That the board approves … and asks the CEO to …"
                                        />
                                    </Field>
                                    <p className="text-caption -mt-2 flex items-center gap-1">
                                        The exact words the board votes on.
                                        <GovernanceTermHint term="resolution_wording" />
                                    </p>
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
                                    {data.purpose && !isDecision ? (
                                        <InfoCard icon={MessageSquare}>
                                            Papers for discussion or for
                                            information don’t go to a vote, so
                                            there’s no voting rule, deadline or
                                            follow-up actions to set.
                                        </InfoCard>
                                    ) : null}
                                    <Field
                                        label="What is it about?"
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
                                    {showAuthority && isDecision ? (
                                        <Field
                                            label="What will this resolution approve? (optional)"
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
                                                    aria-label="What will this resolution approve?"
                                                >
                                                    <SelectValue placeholder="Nothing specific — a general resolution" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        Nothing specific — a
                                                        general resolution
                                                    </SelectItem>
                                                    {currentBinding &&
                                                    !currentBindingListed ? (
                                                        <SelectGroup>
                                                            <SelectLabel>
                                                                Currently linked
                                                            </SelectLabel>
                                                            <SelectItem
                                                                value={bindingKey(
                                                                    currentBinding.subject_type,
                                                                    currentBinding.subject_id,
                                                                )}
                                                            >
                                                                {`${currentBinding.subject_type_label}: ${currentBinding.subject_label}`}
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
                                                If the resolution passes, the
                                                app applies it to this exact
                                                version. If someone changes the
                                                record before then, the board
                                                will need to approve the new
                                                version.
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
                                    title="Options"
                                    blurb="The choices the board could make, and what management recommends."
                                />
                                <div className="grid gap-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-subtle">
                                            {data.options.length === 1
                                                ? '1 option considered'
                                                : `${data.options.length} options considered`}
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
                                                    placeholder="What this option would involve…"
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
                                                        placeholder="What's good about it"
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
                                                        placeholder="Downsides, costs and risks"
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
                                            label="Why there's only one option"
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
                                                placeholder="e.g. The law requires it, or there's only one supplier."
                                            />
                                        </Field>
                                    ) : null}
                                    <Field
                                        label="Management's recommendation"
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
                                    title="Effects"
                                    blurb="What it will cost, and how it affects the people we support, risk and fairness (including Te Tiriti)."
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Cost"
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
                                                    label: 'No cost',
                                                    description:
                                                        "Confirmed: this won't cost anything.",
                                                    icon: CheckCircle2,
                                                },
                                                {
                                                    key: 'cost',
                                                    label: 'Has a cost',
                                                    description:
                                                        'Spending, funding or a budget change.',
                                                    icon: DollarSign,
                                                },
                                            ]}
                                        />
                                    </Field>
                                    {data.has_cost ? (
                                        <>
                                            <Field
                                                label="How much will it cost?"
                                                required
                                            >
                                                <div className="relative">
                                                    <span
                                                        aria-hidden="true"
                                                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                                    >
                                                        $
                                                    </span>
                                                    <Input
                                                        id="resolution-cost-amount"
                                                        inputMode="decimal"
                                                        className="pl-7"
                                                        value={data.cost_amount}
                                                        onChange={(e) =>
                                                            set(
                                                                'cost_amount',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="85000"
                                                    />
                                                </div>
                                            </Field>
                                            <Field label="Where will the money come from?">
                                                <Input
                                                    id="resolution-cost-source"
                                                    value={data.cost_source}
                                                    onChange={(e) =>
                                                        set(
                                                            'cost_source',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. 2026/27 operating budget — regional services"
                                                />
                                            </Field>
                                        </>
                                    ) : null}
                                    <Field
                                        label="Effect on the people we support and safety"
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
                                            placeholder="How it changes support, service quality or safety for the people we support."
                                        />
                                    </Field>
                                    <Field
                                        label="Risks and fairness (including Te Tiriti o Waitangi)"
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
                                            placeholder="The main risks and how they're managed, who could be treated unfairly, and our Te Tiriti commitments."
                                        />
                                    </Field>
                                </div>
                            </>
                        ) : null}

                        {step.key === 'voting' ? (
                            <>
                                <StepHead
                                    icon={Vote}
                                    title="How it passes"
                                    blurb="Choose the voting rule and when voting closes, and the follow-up actions if it passes."
                                />
                                <div className="grid gap-4">
                                    <Field
                                        label="How does it pass?"
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
                                    {isWritten &&
                                    votingRules?.written_unanimity_required ? (
                                        <InfoCard icon={Users}>
                                            The board’s voting rules say votes
                                            outside a meeting need everyone’s
                                            agreement, so this resolution will
                                            only pass if every voting member
                                            votes For — whichever rule you
                                            choose.
                                        </InfoCard>
                                    ) : null}
                                    {writtenNotAllowed ? (
                                        <InfoCard
                                            icon={AlertTriangle}
                                            tone="warn"
                                        >
                                            The board’s voting rules don’t allow
                                            voting outside a meeting yet. Add
                                            this resolution to a meeting, or ask
                                            the chair or board secretary to
                                            change the voting rules in Settings.
                                        </InfoCard>
                                    ) : null}
                                    <Field
                                        label="Voting closes"
                                        required={isWritten}
                                        hint={
                                            isWritten
                                                ? 'New Zealand time — needed before voting can open'
                                                : 'New Zealand time — optional; leave blank if voting closes at the meeting'
                                        }
                                        error={
                                            errorFor('voting_deadline') ??
                                            errorFor('deadline')
                                        }
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
                                                If the resolution passes, each
                                                one becomes an action for the
                                                person responsible.
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
                                                            label="What needs doing"
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
                                                                placeholder="e.g. Sign the new contract"
                                                            />
                                                        </Field>
                                                        <Field
                                                            label="Person responsible"
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
                                                                    aria-label={`Action ${index + 1} person responsible`}
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
                                                                            ? `${action.assignee_name} (no account)`
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
                                            ? 'Check your changes before saving.'
                                            : isDecision
                                              ? 'Check the resolution. You can save a draft at any time; publishing opens voting.'
                                              : 'Check the paper. You can save a draft at any time; publishing shares it with board members.'
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
                                                ? 'This resolution is ready to publish.'
                                                : "This is an incomplete draft. You can save it, but it can't be published until:"}
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
                                    {!isEdit &&
                                    canPublish &&
                                    publishBlockedByRules ? (
                                        <InfoCard
                                            icon={AlertTriangle}
                                            tone="warn"
                                        >
                                            Board voting is switched off until
                                            the voting rules are confirmed, so
                                            for now this can only be saved as a
                                            draft. The chair or board secretary
                                            can confirm the rules in Settings.
                                        </InfoCard>
                                    ) : null}
                                    <Card className="gap-1.5 p-4 shadow-none">
                                        <p className="text-caption font-semibold">
                                            Resolution wording
                                        </p>
                                        {data.exact_motion.trim() ? (
                                            <blockquote className="border-l-4 border-primary pl-3 text-sm whitespace-pre-wrap">
                                                {data.exact_motion}
                                            </blockquote>
                                        ) : (
                                            <p className="text-subtle">
                                                Not written yet.
                                            </p>
                                        )}
                                    </Card>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <ReviewCard
                                            icon={FileText}
                                            title="Title and meeting"
                                            onEdit={() => goToKey('paper')}
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
                                                    ) ??
                                                    'Vote outside a meeting (written resolution)'
                                                }
                                            />
                                            <ReviewRow
                                                label="Committee"
                                                value={committeeLabel(
                                                    data.board_committee_id,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Why it's before the board"
                                                value={excerpt(data.context)}
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Gavel}
                                            title="Wording and purpose"
                                            onEdit={() => goToKey('motion')}
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
                                                label="About"
                                                value={
                                                    data.decision_type
                                                        ? decisionTypeLabel(
                                                              data.decision_type,
                                                          )
                                                        : ''
                                                }
                                            />
                                            {showAuthority && isDecision ? (
                                                <ReviewRow
                                                    label="Approves"
                                                    value={
                                                        authorityLabel(
                                                            data.authority_binding_key,
                                                        ) ?? 'Nothing specific'
                                                    }
                                                />
                                            ) : null}
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Scale}
                                            title="Options"
                                            onEdit={() => goToKey('options')}
                                        >
                                            <ReviewRow
                                                label="Options considered"
                                                value={excerpt(
                                                    data.options
                                                        .map((o) =>
                                                            o.label.trim(),
                                                        )
                                                        .filter(Boolean)
                                                        .join('; '),
                                                )}
                                            />
                                            {data.single_option_reason.trim() ? (
                                                <ReviewRow
                                                    label="Why only one option"
                                                    value={excerpt(
                                                        data.single_option_reason,
                                                    )}
                                                />
                                            ) : null}
                                            <ReviewRow
                                                label="Recommendation"
                                                value={excerpt(
                                                    data.recommendation,
                                                )}
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={ShieldAlert}
                                            title="Effects"
                                            onEdit={() =>
                                                goToKey('implications')
                                            }
                                        >
                                            <ReviewRow
                                                label="Cost"
                                                value={
                                                    data.has_cost
                                                        ? [
                                                              formatNzd(
                                                                  data.cost_amount,
                                                              ),
                                                              data.cost_source.trim(),
                                                          ]
                                                              .filter(Boolean)
                                                              .join(' · ')
                                                        : 'No cost'
                                                }
                                            />
                                            <ReviewRow
                                                label="People we support"
                                                value={excerpt(
                                                    data.service_user_implications,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Risks and fairness"
                                                value={excerpt(
                                                    data.risk_equity_implications,
                                                )}
                                            />
                                        </ReviewCard>
                                        {isDecision ? (
                                            <ReviewCard
                                                icon={ListChecks}
                                                title="How it passes"
                                                onEdit={() => goToKey('voting')}
                                                span
                                            >
                                                <ReviewRow
                                                    label="Voting rule"
                                                    value={
                                                        RESOLUTION_TYPES.find(
                                                            (t) =>
                                                                t.key ===
                                                                data.type,
                                                        )?.label
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Voting closes"
                                                    value={
                                                        data.voting_deadline
                                                            ? `${formatWallTime(data.voting_deadline)} (NZ time)`
                                                            : isWritten
                                                              ? 'Not set yet'
                                                              : 'At the meeting'
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
                                        ) : null}
                                    </div>
                                    {showAuthority &&
                                    isDecision &&
                                    data.authority_binding_key !== NONE ? (
                                        <InfoCard icon={Link2}>
                                            If the resolution passes, the app
                                            applies it to this exact version of
                                            the record. If someone changes the
                                            record before then, the board will
                                            need to approve the new version.
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
                mode={isEdit ? 'edit' : 'create'}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your unsaved changes to this resolution will be lost.'
                        : "This resolution hasn't been saved and will be lost."
                }
            />
        </>
    );
}
