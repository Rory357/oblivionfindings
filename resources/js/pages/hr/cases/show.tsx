import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Briefcase, Calendar, Clock, Plus, User, XCircle } from 'lucide-react';
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

type DisciplinaryActionRow = {
    id: number;
    stage: string;
    action_type: string;
    allegation_summary: string;
    investigation_notes: string | null;
    response_deadline: string | null;
    outcome: string | null;
    good_faith_checklist: Record<string, boolean> | null;
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
    can: { manage: boolean; disciplinary: boolean };
};

const requiredGoodFaithChecks = [
    'allegation_communicated',
    'opportunity_to_respond',
    'response_genuinely_considered',
    'support_person_offered',
];

const badgeClassByStatus: Record<string, string> = {
    open: 'bg-blue-100 text-blue-800 border-blue-200',
    under_investigation: 'bg-purple-100 text-purple-800 border-purple-200',
    awaiting_response: 'bg-amber-100 text-amber-800 border-amber-200',
    resolved: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    closed: 'bg-slate-100 text-slate-800 border-slate-200',
};

const badgeClassByCaseType: Record<string, string> = {
    disciplinary: 'bg-red-100 text-red-800 border-red-200',
    grievance: 'bg-orange-100 text-orange-800 border-orange-200',
    investigation: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    welfare: 'bg-green-100 text-green-800 border-green-200',
    complaint: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    other: 'bg-slate-100 text-slate-800 border-slate-200',
};

const badgeClassBySeverity: Record<string, string> = {
    critical: 'bg-red-100 text-red-800 border-red-200',
    high: 'bg-orange-100 text-orange-800 border-orange-200',
    medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    low: 'bg-slate-100 text-slate-800 border-slate-200',
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-GB', {
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
        : date.toLocaleDateString('en-GB', {
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
            return 'bg-blue-500';
        case 'meeting':
            return 'bg-green-500';
        case 'phone_call':
        case 'email':
            return 'bg-indigo-500';
        case 'investigation_update':
            return 'bg-purple-500';
        case 'letter':
        case 'document':
            return 'bg-amber-500';
        default:
            return 'bg-slate-400';
    }
};

const outcomeStages = ['outcome_decided', 'outcome_communicated', 'closed'];
const visibilityFilterValues = ['all', 'internal', 'restricted', 'full'] as const;
type VisibilityFilter = (typeof visibilityFilterValues)[number];

const visibilityBadgeClass: Record<'internal' | 'restricted' | 'full', string> = {
    internal: 'bg-slate-100 text-slate-800 border-slate-200',
    restricted: 'bg-amber-100 text-amber-800 border-amber-200',
    full: 'bg-emerald-100 text-emerald-800 border-emerald-200',
};

const normalizeVisibility = (value?: string): 'internal' | 'restricted' | 'full' => {
    if (value === 'restricted') return 'restricted';
    if (value === 'full') return 'full';
    return 'internal';
};

export default function HrCaseShow({ case: hrCase, timeline, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
    ];

    const page = usePage<{ errors?: Record<string, string | string[]> }>();

    const isClosed = ['closed', 'resolved'].includes(hrCase.status);
    const disciplinaryActions = hrCase.disciplinary_actions ?? hrCase.disciplinaryActions ?? [];
    const assignedTo = hrCase.assigned_to ?? hrCase.assignedTo ?? null;

    const goodFaithError = page.props?.errors?.good_faith;
    const stageError = page.props?.errors?.stage;
    const [timelineVisibilityFilter, setTimelineVisibilityFilter] = useState<VisibilityFilter>('all');

    const filteredTimeline = useMemo(() => {
        if (timelineVisibilityFilter === 'all') {
            return timeline;
        }

        return timeline.filter((item) => normalizeVisibility(item.visibility) === timelineVisibilityFilter);
    }, [timeline, timelineVisibilityFilter]);

    const [closeCaseDialogOpen, setCloseCaseDialogOpen] = useState(false);
    const [closeCaseOutcome, setCloseCaseOutcome] = useState('');
    const [closeCaseOutcomeType, setCloseCaseOutcomeType] = useState<'resolved' | 'no_action'>('resolved');

    function closeCase() {
        setCloseCaseOutcome('');
        setCloseCaseOutcomeType('resolved');
        setCloseCaseDialogOpen(true);
    }

    function submitCloseCase() {
        if (!closeCaseOutcome.trim()) return;
        router.post(`/hr/cases/${hrCase.id}/close`, {
            outcome: closeCaseOutcome.trim(),
            outcome_type: closeCaseOutcomeType,
        }, {
            onSuccess: () => setCloseCaseDialogOpen(false),
        });
    }

    function advanceDisciplinaryStage(actionId: number) {
        router.post(`/hr/cases/disciplinary/${actionId}/advance`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Case ${hrCase.case_number}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <Briefcase className="h-5 w-5 text-slate-500" />
                            {hrCase.case_number}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={badgeClassByStatus[hrCase.status] ?? badgeClassByStatus.closed}>
                                {hrCase.status.replace(/_/g, ' ')}
                            </Badge>
                            <Badge className={badgeClassByCaseType[hrCase.case_type] ?? badgeClassByCaseType.other}>
                                {hrCase.case_type.replace(/_/g, ' ')}
                            </Badge>
                            <Badge className={badgeClassBySeverity[hrCase.severity] ?? badgeClassBySeverity.low}>
                                {hrCase.severity}
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/hr/cases" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            <ArrowLeft className="mr-1 inline h-3.5 w-3.5" />
                            Back to list
                        </Link>
                        {can.manage && !isClosed ? (
                            <>
                                <Link href={`/hr/cases/${hrCase.id}/events/create`}>
                                    <Button size="sm" variant="outline">
                                        <Calendar className="mr-1.5 h-4 w-4" />
                                        Add Event
                                    </Button>
                                </Link>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-red-200 text-red-600 hover:bg-red-50"
                                    onClick={closeCase}
                                >
                                    <XCircle className="mr-1.5 h-4 w-4" />
                                    Close Case
                                </Button>
                            </>
                        ) : null}
                    </div>
                </div>

                {goodFaithError || stageError ? (
                    <Card className="border-red-200 bg-red-50">
                        <CardContent className="py-3 text-sm text-red-700">
                            {Array.isArray(goodFaithError)
                                ? goodFaithError.join(' ')
                                : goodFaithError || (Array.isArray(stageError) ? stageError.join(' ') : stageError)}
                        </CardContent>
                    </Card>
                ) : null}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Briefcase className="h-5 w-5 text-blue-500" />
                                Case Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-xs text-slate-500">Title</div>
                                <div className="font-medium">{hrCase.title}</div>
                            </div>
                            <div className="whitespace-pre-wrap text-sm text-slate-700">{hrCase.description}</div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-slate-500">Opened</div>
                                    <div className="font-medium">{formatDate(hrCase.opened_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Closed</div>
                                    <div className="font-medium">{formatDate(hrCase.closed_at)}</div>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-slate-500">Outcome Type</div>
                                    <div className="font-medium">{hrCase.outcome_type ? hrCase.outcome_type.replace(/_/g, ' ') : 'Not set'}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Outcome</div>
                                    <div className="font-medium">{hrCase.outcome || 'Not set'}</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-green-500" />
                                People
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Subject</div>
                                <div className="font-medium">{hrCase.subject?.name || 'Unknown'}</div>
                                {hrCase.subject?.email ? <div className="text-xs text-slate-400">{hrCase.subject.email}</div> : null}
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Opened By</div>
                                <div className="font-medium">{hrCase.reported_by?.name || 'Unknown'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Assigned To</div>
                                <div className="font-medium">{assignedTo?.name || 'Unassigned'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-5 w-5 text-purple-500" />
                                Timeline
                            </CardTitle>
                            <div className="w-full sm:w-56">
                                <Select
                                    value={timelineVisibilityFilter}
                                    onValueChange={(value) => setTimelineVisibilityFilter(value as VisibilityFilter)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Visibility filter" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {visibilityFilterValues.map((value) => (
                                            <SelectItem key={value} value={value}>
                                                {value === 'all' ? 'All visible events' : value}
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
                                <div className="absolute bottom-2 left-[9px] top-2 w-0.5 bg-slate-200" />
                                {filteredTimeline.map((item) => (
                                    <div key={`${item.type}-${item.id}`} className="relative">
                                        <div className={`absolute -left-6 top-1.5 h-3 w-3 rounded-full ${getEventDotClass(item.event_type)}`} />
                                        <div className="rounded-md border p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <Badge variant="outline" className="mb-1 mr-1 capitalize">
                                                        {item.event_type.replace(/_/g, ' ')}
                                                    </Badge>
                                                    <Badge className={visibilityBadgeClass[normalizeVisibility(item.visibility)]}>
                                                        {normalizeVisibility(item.visibility)}
                                                    </Badge>
                                                    <div className="text-sm font-medium">{item.title}</div>
                                                    {item.description ? <div className="text-sm text-slate-700">{item.description}</div> : null}
                                                </div>
                                                <div className="shrink-0 text-xs text-slate-500">{formatDateTime(item.occurred_at)}</div>
                                            </div>
                                            {item.created_by ? <div className="mt-1 text-xs text-slate-400">By {item.created_by}</div> : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-6 text-center text-sm text-slate-500">
                                {timeline.length > 0 ? 'No timeline events for this visibility filter.' : 'No events recorded yet.'}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {disciplinaryActions.length > 0 || can.disciplinary ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-5 w-5 text-red-500" />
                                    Disciplinary Actions
                                </CardTitle>
                                {can.disciplinary && !isClosed ? (
                                    <Link href={`/hr/cases/${hrCase.id}/disciplinary/create`}>
                                        <Button size="sm" variant="outline">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add Action
                                        </Button>
                                    </Link>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {disciplinaryActions.map((action) => {
                                const checklist = action.good_faith_checklist ?? {};
                                const missingChecks = requiredGoodFaithChecks.filter((key) => !checklist[key]);
                                const canAdvance = !isClosed && action.stage !== 'closed';

                                return (
                                    <div key={action.id} className="rounded-md border p-3">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="space-y-1">
                                                <div className="font-medium capitalize">
                                                    {action.action_type.replace(/_/g, ' ')} - {action.stage.replace(/_/g, ' ')}
                                                </div>
                                                <div className="text-sm text-slate-700">{action.allegation_summary}</div>
                                                {action.outcome ? <div className="text-sm text-slate-600">Outcome: {action.outcome}</div> : null}
                                                <div className="text-xs text-slate-500">
                                                    Employee: {action.employee?.name ?? 'Unknown'} | Investigator: {action.investigator?.name ?? 'Unassigned'} | Created:{' '}
                                                    {formatDate(action.created_at)}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    Response deadline: {formatDate(action.response_deadline)}
                                                </div>
                                            </div>
                                            {can.disciplinary ? (
                                                <div className="flex items-center gap-2">
                                                    <Link href={`/hr/cases/disciplinary/${action.id}/edit`}>
                                                        <Button size="sm" variant="outline">
                                                            Edit
                                                        </Button>
                                                    </Link>
                                                    {canAdvance ? (
                                                        <Button size="sm" variant="outline" onClick={() => advanceDisciplinaryStage(action.id)}>
                                                            Advance Stage
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                        </div>

                                        {outcomeStages.includes(action.stage) ? (
                                            <div className="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                                                Good-faith checklist: {missingChecks.length === 0 ? 'complete' : `${missingChecks.length} item(s) missing`}
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                            {disciplinaryActions.length === 0 ? (
                                <p className="py-4 text-center text-sm text-slate-500">No disciplinary actions recorded.</p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </div>

            <Dialog open={closeCaseDialogOpen} onOpenChange={setCloseCaseDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close Case</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="case-outcome">Outcome Summary (required)</Label>
                            <Textarea
                                id="case-outcome"
                                value={closeCaseOutcome}
                                onChange={(e) => setCloseCaseOutcome(e.target.value)}
                                placeholder="Enter the final case outcome summary..."
                                rows={3}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Outcome Type</Label>
                            <RadioGroup value={closeCaseOutcomeType} onValueChange={(v) => setCloseCaseOutcomeType(v as 'resolved' | 'no_action')}>
                                <div className="flex items-center gap-2">
                                    <RadioGroupItem value="resolved" id="resolved" />
                                    <Label htmlFor="resolved">Resolved</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <RadioGroupItem value="no_action" id="no_action" />
                                    <Label htmlFor="no_action">No Action</Label>
                                </div>
                            </RadioGroup>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCloseCaseDialogOpen(false)}>Cancel</Button>
                        <Button onClick={submitCloseCase} disabled={!closeCaseOutcome.trim()}>Close Case</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
