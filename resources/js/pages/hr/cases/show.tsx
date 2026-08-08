import {
    CaseEventWizard,
    DisciplinaryCreateWizard,
    DisciplinaryEditWizard,
    type CaseIncidentOption,
    type CaseOption,
    type CaseStaffOption,
    type DisciplinaryActionForm,
    type GoodFaithCheckOption,
} from '@/components/hr/case-wizards';
import { PageHero, PageLayout } from '@/components/page';
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
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Briefcase,
    Calendar,
    Clock,
    ExternalLink,
    Plus,
    User,
    UserMinus,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type UserRef = {
    id: number;
    name: string;
    email?: string;
} | null;

type TimelineRow = {
    type: string;
    id: number;
    title: string;
    description: string | null;
    event_type: string;
    occurred_at: string;
    created_by: string | null;
    visibility?: 'internal' | 'restricted' | 'full';
};

type DisciplinaryActionRow = DisciplinaryActionForm & {
    employee?: UserRef;
    investigator?: UserRef;
    created_at: string;
};

type HrCasePayload = {
    id: number;
    case_number: string;
    case_type: string;
    severity: string;
    status: string;
    title: string;
    description: string;
    opened_at: string;
    closed_at: string | null;
    outcome: string | null;
    outcome_type: string | null;
    subject?: UserRef;
    reported_by?: UserRef;
    assigned_to?: UserRef;
    assignedTo?: UserRef;
    disciplinary_actions?: DisciplinaryActionRow[];
    disciplinaryActions?: DisciplinaryActionRow[];
};

type Props = {
    case: HrCasePayload;
    timeline: TimelineRow[];
    linkedIncidents: CaseIncidentOption[];
    can: {
        manage: boolean;
        disciplinary: boolean;
        view_incidents: boolean;
    };
    staff: CaseStaffOption[];
    eventTypes: CaseOption[];
    actionTypes: CaseOption[];
    stageOptions: CaseOption[];
    goodFaithRequiredChecks: GoodFaithCheckOption[];
};

type CaseModalState =
    | { type: 'event' }
    | { type: 'disciplinary' }
    | { type: 'edit-disciplinary'; actionId: number }
    | null;

/** Initial modal from the URL — old GET form routes redirect here with these params. */
function initialModal(): CaseModalState {
    if (typeof window === 'undefined') return null;
    const params = new URLSearchParams(window.location.search);
    const editId = Number(params.get('edit-disciplinary'));
    if (Number.isInteger(editId) && editId > 0) {
        return { type: 'edit-disciplinary', actionId: editId };
    }
    const wanted = params.get('new');
    if (wanted === 'event') return { type: 'event' };
    if (wanted === 'disciplinary') return { type: 'disciplinary' };
    return null;
}

const requiredGoodFaithChecks = [
    'allegation_communicated',
    'opportunity_to_respond',
    'response_genuinely_considered',
    'support_person_offered',
];

const badgeClassByStatus: Record<string, string> = {
    open: 'bg-status-info-bg text-status-info border-status-info/30',
    under_investigation: 'bg-primary/10 text-primary border-primary',
    awaiting_response:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    resolved:
        'bg-status-success-bg text-status-success border-status-success/30',
    closed: 'bg-muted text-foreground border-border',
};

const badgeClassByCaseType: Record<string, string> = {
    disciplinary:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    grievance:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    investigation: 'bg-primary/10 text-primary border-primary',
    welfare:
        'bg-status-success-bg text-status-success border-status-success/30',
    complaint: 'bg-status-info-bg text-status-info border-status-info/30',
    other: 'bg-muted text-foreground border-border',
};

const badgeClassBySeverity: Record<string, string> = {
    critical:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    medium: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    low: 'bg-muted text-foreground border-border',
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

const getEventDotClass = (eventType: string) => {
    switch (eventType) {
        case 'note':
            return 'bg-status-info';
        case 'meeting':
            return 'bg-status-success';
        case 'phone_call':
        case 'email':
            return 'bg-primary';
        case 'investigation_update':
            return 'bg-primary';
        case 'letter':
        case 'document':
            return 'bg-status-warning';
        default:
            return 'bg-muted';
    }
};

const outcomeStages = ['outcome_decided', 'outcome_communicated', 'closed'];
const visibilityFilterValues = [
    'all',
    'internal',
    'restricted',
    'full',
] as const;
type VisibilityFilter = (typeof visibilityFilterValues)[number];

const visibilityBadgeClass: Record<'internal' | 'restricted' | 'full', string> =
    {
        internal: 'bg-muted text-foreground border-border',
        restricted:
            'bg-status-warning-bg text-status-warning border-status-warning/30',
        full: 'bg-status-success-bg text-status-success border-status-success/30',
    };

const normalizeVisibility = (
    value?: string,
): 'internal' | 'restricted' | 'full' => {
    if (value === 'restricted') return 'restricted';
    if (value === 'full') return 'full';
    return 'internal';
};

export default function HrCaseShow({
    case: hrCase,
    timeline,
    linkedIncidents,
    can,
    staff,
    eventTypes,
    actionTypes,
    stageOptions,
    goodFaithRequiredChecks,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
    ];

    const page = usePage<{
        errors?: Record<string, string | string[]>;
        flash?: {
            offboarding_cta?: {
                label: string;
                url: string;
                employee_name?: string | null;
            } | null;
        };
    }>();

    // Dismissal outcome → explicit "Start offboarding" next step (flashed by
    // DisciplinaryController; nothing is auto-created).
    const offboardingCta = page.props?.flash?.offboarding_cta ?? null;

    const isClosed = ['closed', 'resolved'].includes(hrCase.status);
    const disciplinaryActions =
        hrCase.disciplinary_actions ?? hrCase.disciplinaryActions ?? [];
    const assignedTo = hrCase.assigned_to ?? hrCase.assignedTo ?? null;

    const goodFaithError = page.props?.errors?.good_faith;
    const stageError = page.props?.errors?.stage;
    const [timelineVisibilityFilter, setTimelineVisibilityFilter] =
        useState<VisibilityFilter>('all');

    const filteredTimeline = useMemo(() => {
        if (timelineVisibilityFilter === 'all') {
            return timeline;
        }

        return timeline.filter(
            (item) =>
                normalizeVisibility(item.visibility) ===
                timelineVisibilityFilter,
        );
    }, [timeline, timelineVisibilityFilter]);

    const [modal, setModal] = useState<CaseModalState>(initialModal);

    const editingAction =
        modal?.type === 'edit-disciplinary'
            ? (disciplinaryActions.find((a) => a.id === modal.actionId) ?? null)
            : null;

    const [closeCaseDialogOpen, setCloseCaseDialogOpen] = useState(false);
    const [closeCaseOutcome, setCloseCaseOutcome] = useState('');
    const [closeCaseOutcomeType, setCloseCaseOutcomeType] = useState<
        'resolved' | 'no_action'
    >('resolved');

    function closeCase() {
        setCloseCaseOutcome('');
        setCloseCaseOutcomeType('resolved');
        setCloseCaseDialogOpen(true);
    }

    function submitCloseCase() {
        if (!closeCaseOutcome.trim()) return;
        router.post(
            `/hr/cases/${hrCase.id}/close`,
            {
                outcome: closeCaseOutcome.trim(),
                outcome_type: closeCaseOutcomeType,
            },
            {
                onSuccess: () => setCloseCaseDialogOpen(false),
            },
        );
    }

    function advanceDisciplinaryStage(actionId: number) {
        router.post(
            `/hr/cases/disciplinary/${actionId}/advance`,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Case ${hrCase.case_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        variant="compact"
                        backHref="/hr/cases"
                        title={
                            <span className="flex items-center gap-2">
                                <Briefcase className="h-5 w-5 text-muted-foreground" />
                                {hrCase.case_number}
                            </span>
                        }
                        description={
                            <span className="flex flex-wrap gap-2">
                                <Badge
                                    className={
                                        badgeClassByStatus[hrCase.status] ??
                                        badgeClassByStatus.closed
                                    }
                                >
                                    {hrCase.status.replace(/_/g, ' ')}
                                </Badge>
                                <Badge
                                    className={
                                        badgeClassByCaseType[
                                            hrCase.case_type
                                        ] ?? badgeClassByCaseType.other
                                    }
                                >
                                    {hrCase.case_type.replace(/_/g, ' ')}
                                </Badge>
                                <Badge
                                    className={
                                        badgeClassBySeverity[hrCase.severity] ??
                                        badgeClassBySeverity.low
                                    }
                                >
                                    {hrCase.severity}
                                </Badge>
                            </span>
                        }
                        actions={
                            can.manage && !isClosed ? (
                                <>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setModal({ type: 'event' })
                                        }
                                    >
                                        <Calendar className="mr-1.5 h-4 w-4" />
                                        Add Event
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                        onClick={closeCase}
                                    >
                                        <XCircle className="mr-1.5 h-4 w-4" />
                                        Close Case
                                    </Button>
                                </>
                            ) : undefined
                        }
                    />
                }
            >
                {offboardingCta ? (
                    <Card className="border-status-warning/40 bg-status-warning-bg/40">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="flex items-center gap-2 text-sm">
                                <UserMinus className="h-4 w-4 shrink-0 text-status-warning" />
                                <span>
                                    Dismissal outcome recorded
                                    {offboardingCta.employee_name
                                        ? ` for ${offboardingCta.employee_name}`
                                        : ''}
                                    . Next step: start the offboarding
                                    checklist.
                                </span>
                            </div>
                            <Button
                                size="sm"
                                onClick={() => router.visit(offboardingCta.url)}
                            >
                                {offboardingCta.label}
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}

                {goodFaithError || stageError ? (
                    <Card className="border-status-critical/30 bg-status-critical-bg">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(goodFaithError)
                                ? goodFaithError.join(' ')
                                : goodFaithError ||
                                  (Array.isArray(stageError)
                                      ? stageError.join(' ')
                                      : stageError)}
                        </CardContent>
                    </Card>
                ) : null}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Briefcase className="h-5 w-5 text-status-info" />
                                Case Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-xs text-muted-foreground">
                                    Title
                                </div>
                                <div className="font-medium">
                                    {hrCase.title}
                                </div>
                            </div>
                            <div className="text-sm whitespace-pre-wrap text-foreground">
                                {hrCase.description}
                            </div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Opened
                                    </div>
                                    <div className="font-medium">
                                        {formatDate(hrCase.opened_at)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Closed
                                    </div>
                                    <div className="font-medium">
                                        {formatDate(hrCase.closed_at)}
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Outcome Type
                                    </div>
                                    <div className="font-medium">
                                        {hrCase.outcome_type
                                            ? hrCase.outcome_type.replace(
                                                  /_/g,
                                                  ' ',
                                              )
                                            : 'Not set'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Outcome
                                    </div>
                                    <div className="font-medium">
                                        {hrCase.outcome || 'Not set'}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-status-success" />
                                People
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">
                                    Subject
                                </div>
                                <div className="font-medium">
                                    {hrCase.subject?.name || 'Unknown'}
                                </div>
                                {hrCase.subject?.email ? (
                                    <div className="text-xs text-muted-foreground">
                                        {hrCase.subject.email}
                                    </div>
                                ) : null}
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">
                                    Opened By
                                </div>
                                <div className="font-medium">
                                    {hrCase.reported_by?.name || 'Unknown'}
                                </div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">
                                    Assigned To
                                </div>
                                <div className="font-medium">
                                    {assignedTo?.name || 'Unassigned'}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {linkedIncidents.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-5 w-5 text-status-warning" />
                                Linked incidents
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Read-only incident references. Health &amp;
                                Safety remains the owner of these records.
                            </p>
                            <div className="divide-y rounded-lg border">
                                {linkedIncidents.map((incident) => (
                                    <div
                                        key={incident.id}
                                        className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    {incident.reference}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className="capitalize"
                                                >
                                                    {incident.severity}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="capitalize"
                                                >
                                                    {incident.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <p className="mt-1 text-sm">
                                                {incident.title}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {incident.client ??
                                                    'Unknown client'}{' '}
                                                ·{' '}
                                                {formatDate(
                                                    incident.occurred_at,
                                                )}
                                            </p>
                                        </div>
                                        {can.view_incidents ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <Link
                                                    href={`/incidents/${incident.id}`}
                                                >
                                                    Open in H&amp;S
                                                    <ExternalLink className="ml-2 h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-5 w-5 text-primary" />
                                Timeline
                            </CardTitle>
                            <div className="w-full sm:w-56">
                                <Select
                                    value={timelineVisibilityFilter}
                                    onValueChange={(value) =>
                                        setTimelineVisibilityFilter(
                                            value as VisibilityFilter,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Visibility filter" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {visibilityFilterValues.map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {value === 'all'
                                                    ? 'All visible events'
                                                    : value}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {filteredTimeline.length > 0 ? (
                            <div className="relative space-y-4 pl-6">
                                <div className="absolute top-2 bottom-2 left-[9px] w-0.5 bg-muted" />
                                {filteredTimeline.map((item) => (
                                    <div
                                        key={`${item.type}-${item.id}`}
                                        className="relative"
                                    >
                                        <div
                                            className={`absolute top-1.5 -left-6 h-3 w-3 rounded-full ${getEventDotClass(item.event_type)}`}
                                        />
                                        <div className="rounded-md border p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <Badge
                                                        variant="outline"
                                                        className="mr-1 mb-1 capitalize"
                                                    >
                                                        {item.event_type.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                    <Badge
                                                        className={
                                                            visibilityBadgeClass[
                                                                normalizeVisibility(
                                                                    item.visibility,
                                                                )
                                                            ]
                                                        }
                                                    >
                                                        {normalizeVisibility(
                                                            item.visibility,
                                                        )}
                                                    </Badge>
                                                    <div className="text-sm font-medium">
                                                        {item.title}
                                                    </div>
                                                    {item.description ? (
                                                        <div className="text-sm text-foreground">
                                                            {item.description}
                                                        </div>
                                                    ) : null}
                                                </div>
                                                <div className="shrink-0 text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        item.occurred_at,
                                                    )}
                                                </div>
                                            </div>
                                            {item.created_by ? (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    By {item.created_by}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-6 text-center text-sm text-muted-foreground">
                                {timeline.length > 0
                                    ? 'No timeline events for this visibility filter.'
                                    : 'No events recorded yet.'}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {disciplinaryActions.length > 0 || can.disciplinary ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-5 w-5 text-status-critical" />
                                    Disciplinary Actions
                                </CardTitle>
                                {can.disciplinary && !isClosed ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setModal({ type: 'disciplinary' })
                                        }
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Action
                                    </Button>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {disciplinaryActions.map((action) => {
                                const checklist =
                                    action.good_faith_checklist ?? {};
                                const missingChecks =
                                    requiredGoodFaithChecks.filter(
                                        (key) => !checklist[key],
                                    );
                                const canAdvance =
                                    !isClosed && action.stage !== 'closed';

                                return (
                                    <div
                                        key={action.id}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="space-y-1">
                                                <div className="font-medium capitalize">
                                                    {action.action_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}{' '}
                                                    -{' '}
                                                    {action.stage.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </div>
                                                <div className="text-sm text-foreground">
                                                    {action.allegation_summary}
                                                </div>
                                                {action.outcome ? (
                                                    <div className="text-sm text-muted-foreground">
                                                        Outcome:{' '}
                                                        {action.outcome}
                                                    </div>
                                                ) : null}
                                                <div className="text-xs text-muted-foreground">
                                                    Employee:{' '}
                                                    {action.employee?.name ??
                                                        'Unknown'}{' '}
                                                    | Investigator:{' '}
                                                    {action.investigator
                                                        ?.name ??
                                                        'Unassigned'}{' '}
                                                    | Created:{' '}
                                                    {formatDate(
                                                        action.created_at,
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Response deadline:{' '}
                                                    {formatDate(
                                                        action.response_deadline,
                                                    )}
                                                </div>
                                            </div>
                                            {can.disciplinary ? (
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setModal({
                                                                type: 'edit-disciplinary',
                                                                actionId:
                                                                    action.id,
                                                            })
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    {canAdvance ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                advanceDisciplinaryStage(
                                                                    action.id,
                                                                )
                                                            }
                                                        >
                                                            Advance Stage
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                        </div>

                                        {outcomeStages.includes(
                                            action.stage,
                                        ) ? (
                                            <div className="mt-2 rounded border border-status-warning/30 bg-status-warning-bg px-2 py-1 text-xs text-status-warning">
                                                Good-faith checklist:{' '}
                                                {missingChecks.length === 0
                                                    ? 'complete'
                                                    : `${missingChecks.length} item(s) missing`}
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                            {disciplinaryActions.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No disciplinary actions recorded.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </PageLayout>

            {modal?.type === 'event' && can.manage ? (
                <CaseEventWizard
                    caseId={hrCase.id}
                    caseNumber={hrCase.case_number}
                    subjectName={hrCase.subject?.name ?? null}
                    eventTypes={eventTypes}
                    onClose={() => setModal(null)}
                />
            ) : null}

            {modal?.type === 'disciplinary' && can.disciplinary ? (
                <DisciplinaryCreateWizard
                    caseId={hrCase.id}
                    caseNumber={hrCase.case_number}
                    subjectId={hrCase.subject?.id ?? null}
                    subjectName={hrCase.subject?.name ?? null}
                    staff={staff}
                    actionTypes={actionTypes}
                    onClose={() => setModal(null)}
                />
            ) : null}

            {editingAction && can.disciplinary ? (
                <DisciplinaryEditWizard
                    key={editingAction.id}
                    action={editingAction}
                    caseNumber={hrCase.case_number}
                    staff={staff}
                    actionTypes={actionTypes}
                    stageOptions={stageOptions}
                    goodFaithRequiredChecks={goodFaithRequiredChecks}
                    onClose={() => setModal(null)}
                />
            ) : null}

            <Dialog
                open={closeCaseDialogOpen}
                onOpenChange={setCloseCaseDialogOpen}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close Case</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="case-outcome">
                                Outcome Summary (required)
                            </Label>
                            <Textarea
                                id="case-outcome"
                                value={closeCaseOutcome}
                                onChange={(e) =>
                                    setCloseCaseOutcome(e.target.value)
                                }
                                placeholder="Enter the final case outcome summary..."
                                rows={3}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Outcome Type</Label>
                            <RadioGroup
                                value={closeCaseOutcomeType}
                                onValueChange={(v) =>
                                    setCloseCaseOutcomeType(
                                        v as 'resolved' | 'no_action',
                                    )
                                }
                            >
                                <div className="flex items-center gap-2">
                                    <RadioGroupItem
                                        value="resolved"
                                        id="resolved"
                                    />
                                    <Label htmlFor="resolved">Resolved</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <RadioGroupItem
                                        value="no_action"
                                        id="no_action"
                                    />
                                    <Label htmlFor="no_action">No Action</Label>
                                </div>
                            </RadioGroup>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCloseCaseDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitCloseCase}
                            disabled={!closeCaseOutcome.trim()}
                        >
                            Close Case
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
