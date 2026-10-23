import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ActionOwnerPane,
    ActionOwnershipHistory,
    type ActionOwnership,
} from '@/components/health-safety/action-owner-pane';
import {
    ActionEvidencePanel,
    CompleteActionPane,
    ReturnActionPane,
    VerifyActionPane,
    type EventCorrectiveAction,
} from '@/components/health-safety/event-detail-dialog';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    SafetyFormDraftContext,
    useSafetyForm,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    correctiveActionHref,
    safetyReviewReturn,
    type CorrectiveActionPane,
    type CorrectiveActionSection,
} from '@/lib/health-safety/navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    FileText,
    History,
    ListChecks,
    Paperclip,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';

export type CorrectiveActionWorkspace = {
    action: EventCorrectiveAction & {
        description: string | null;
        closed_at: string | null;
    };
    review: {
        id: number;
        reference_number: string;
        status: string;
        site_name: string | null;
        url: string;
    };
    source_task_url: string | null;
    ownership: ActionOwnership;
    can: Record<'start' | 'complete' | 'verify' | 'return' | 'close', boolean>;
};
const sections = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
] as const;
const statuses: Record<string, string> = {
    open: 'Not started',
    in_progress: 'In progress',
    completed: 'Awaiting verification',
    verified: 'Verified',
    closed: 'Closed',
};

export default function CorrectiveActionShow({
    workspace,
}: {
    workspace: CorrectiveActionWorkspace;
}) {
    const { action, review } = workspace;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Corrective actions',
                    href: '/health-safety/corrective-actions',
                },
                {
                    title: action.reference_number,
                    href: correctiveActionHref(action.id),
                },
            ]}
        >
            <Head title={`${action.reference_number} · Corrective action`} />
            <ActionRecord
                key={action.id}
                workspace={workspace}
                reviewId={review.id}
            />
        </AppLayout>
    );
}

function ActionRecord({
    workspace,
    reviewId,
}: {
    workspace: CorrectiveActionWorkspace;
    reviewId: number;
}) {
    const { action: ca, review, can } = workspace;
    const pageUrl = usePage().url;
    const params = new URLSearchParams(
        pageUrl.split('?')[1]?.split('#')[0] ?? '',
    );
    const section: CorrectiveActionSection =
        params.get('section') === 'evidence'
            ? 'evidence'
            : params.get('section') === 'history'
              ? 'history'
              : 'overview';
    const rawAction = params.get('action');
    const pane: CorrectiveActionPane | null = [
        'complete',
        'verify',
        'return',
        'owner',
    ].includes(rawAction ?? '')
        ? (rawAction as CorrectiveActionPane)
        : null;
    const returnTo = params.has('return_to')
        ? safetyReviewReturn(params.get('return_to'))
        : '/health-safety/corrective-actions';
    const draft = useSafetyWorkspaceDrafts();
    const registerDraft = draft.register;
    const [confirmation, setConfirmation] = useState<'start' | 'close' | null>(
        null,
    );
    const command = useSafetyForm({});
    useEffect(() => {
        registerDraft('lifecycle-command', {
            dirty: false,
            busy: command.processing,
        });
        return () => registerDraft('lifecycle-command', null);
    }, [registerDraft, command.processing]);
    const status = statuses[ca.status] ?? 'Needs review';
    const loaded = ca.evidence.load_state === 'loaded';
    const changeView = (
        nextSection: CorrectiveActionSection,
        nextPane: CorrectiveActionPane | null = null,
    ) =>
        router.replace({
            url: correctiveActionHref(ca.id, {
                section: nextSection,
                action: nextPane,
                returnTo,
            }),
            preserveState: true,
            preserveScroll: true,
        });
    const select = (
        nextSection: CorrectiveActionSection,
        nextPane: CorrectiveActionPane | null = null,
    ) => draft.request(() => changeView(nextSection, nextPane));
    const context = {
        id: reviewId,
        can: { verify_corrective_actions: can.verify },
    };
    const done = () => changeView('overview');
    const cancel = () => draft.request(done);
    const canOpenPane =
        pane === 'owner' ? workspace.ownership.can_assign : pane && can[pane];
    return (
        <SafetyFormDraftContext.Provider value={draft.register}>
            {draft.confirmation}
            <ConfirmDialog
                open={confirmation !== null}
                onClose={() => {
                    if (!command.processing) setConfirmation(null);
                }}
                frontline
                processing={command.processing}
                variant="default"
                title={
                    confirmation === 'start'
                        ? 'Start this action?'
                        : 'Close this verified action?'
                }
                description={
                    confirmation === 'start'
                        ? 'Record that work on this corrective action is underway.'
                        : 'This closes the corrective action. Incident and safety review closure remain separate decisions.'
                }
                confirmText={
                    confirmation === 'start' ? 'Start action' : 'Close action'
                }
                onConfirm={() => {
                    if (
                        !confirmation ||
                        !can[confirmation] ||
                        command.processing
                    )
                        return;
                    command.post(
                        `/health-safety/events/${reviewId}/corrective-actions/${ca.id}/${confirmation}`,
                        {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => setConfirmation(null),
                        },
                    );
                }}
            />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        icon={ListChecks}
                        title={ca.reference_number}
                        backHref={returnTo}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    ca.status === 'closed' ||
                                    ca.status === 'verified'
                                        ? 'success'
                                        : 'info'
                                }
                                icon={
                                    ca.status === 'closed' ||
                                    ca.status === 'verified'
                                        ? CheckCircle2
                                        : Clock
                                }
                            >
                                {status}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            review.site_name,
                            ca.priority
                                ? `${ca.priority[0].toUpperCase()}${ca.priority.slice(1)} priority`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        mobileSummary={status}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Due date"
                                    onClick={() => select('overview')}
                                >
                                    <PageHeaderMeterBig>
                                        {ca.due_date
                                            ? formatDateOnly(ca.due_date)
                                            : 'Not recorded'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {ca.is_overdue
                                            ? 'Overdue'
                                            : 'Action deadline'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence"
                                    onClick={() => select('evidence')}
                                >
                                    <PageHeaderMeterBig>
                                        {loaded
                                            ? ca.evidence.attachments.length
                                            : 'Unavailable'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {loaded
                                            ? 'Retained files'
                                            : 'Evidence is unavailable'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="History"
                                    onClick={() => select('history')}
                                >
                                    <PageHeaderMeterBig>
                                        {loaded
                                            ? ca.history.length +
                                              workspace.ownership.history.length
                                            : 'Unavailable'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded activity
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Safety review"
                                    href={review.url}
                                >
                                    <PageHeaderMeterBig>
                                        {review.reference_number}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Open linked review
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[...sections]}
                                value={section}
                                onSelect={(key) => select(key)}
                                ariaLabel="Corrective action sections"
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 gap-5 lg:grid-cols-3">
                    <Card
                        className={`min-w-0 lg:order-first lg:col-span-2 ${rawAction || section !== 'overview' ? 'order-first' : 'order-last'}`}
                    >
                        <CardContent className="frontline-dialog p-5">
                            {rawAction && !canOpenPane ? (
                                <div className="space-y-3">
                                    <h2 className="text-section-title">
                                        This action is unavailable
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        The record or your access has changed.
                                        Return to the action to see its current
                                        status.
                                    </p>
                                    <Button
                                        className="frontline-tap"
                                        onClick={() => select('overview')}
                                    >
                                        Return to action
                                    </Button>
                                </div>
                            ) : pane === 'owner' ? (
                                <ActionOwnerPane
                                    actionId={ca.id}
                                    owner={ca.owner}
                                    ownership={workspace.ownership}
                                    onDone={done}
                                    onCancel={cancel}
                                />
                            ) : pane === 'complete' ? (
                                <CompleteActionPane
                                    d={context}
                                    ca={ca}
                                    onDone={done}
                                    onCancel={cancel}
                                />
                            ) : pane === 'verify' ? (
                                <VerifyActionPane
                                    d={context}
                                    ca={ca}
                                    onDone={done}
                                    onCancel={cancel}
                                />
                            ) : pane === 'return' ? (
                                <ReturnActionPane
                                    d={context}
                                    ca={ca}
                                    onDone={done}
                                    onCancel={cancel}
                                />
                            ) : section === 'evidence' ? (
                                <div className="space-y-4">
                                    <h2 className="text-section-title">
                                        Completion evidence
                                    </h2>
                                    <p className="text-sm whitespace-pre-wrap">
                                        {ca.evidence.completion_notes ??
                                            (loaded
                                                ? 'No completion notes have been recorded.'
                                                : 'Completion details are unavailable with your current access.')}
                                    </p>
                                    <ActionEvidencePanel
                                        d={context}
                                        ca={ca}
                                        allowUpload={ca.evidence.can_upload}
                                    />
                                    {ca.evidence.completed_by ? (
                                        <p className="text-sm text-muted-foreground">
                                            Submitted by{' '}
                                            {ca.evidence.completed_by.name}
                                            {ca.evidence.completed_at
                                                ? ` · ${formatDateTime(ca.evidence.completed_at)}`
                                                : ''}
                                        </p>
                                    ) : null}
                                </div>
                            ) : section === 'history' ? (
                                <div className="space-y-4">
                                    <h2 className="text-section-title">
                                        Action history
                                    </h2>
                                    <ActionOwnershipHistory
                                        ownership={workspace.ownership}
                                    />
                                    {ca.history.length ? (
                                        <ol className="space-y-4">
                                            {ca.history.map((entry, index) => (
                                                <li
                                                    key={`${entry.occurred_at}-${index}`}
                                                >
                                                    <p className="text-sm font-semibold">
                                                        {entry.label}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {[
                                                            entry.actor,
                                                            entry.occurred_at
                                                                ? formatDateTime(
                                                                      entry.occurred_at,
                                                                  )
                                                                : null,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </p>
                                                    {entry.detail ? (
                                                        <p className="mt-1 text-sm whitespace-pre-wrap">
                                                            {entry.detail}
                                                        </p>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ol>
                                    ) : workspace.ownership.history.length ===
                                      0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            {loaded
                                                ? 'No action history is available.'
                                                : 'Action history is unavailable with your current access.'}
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="space-y-5">
                                    <h2 className="text-section-title">
                                        {ca.title}
                                    </h2>
                                    {ca.description ? (
                                        <p className="text-sm whitespace-pre-wrap">
                                            {ca.description}
                                        </p>
                                    ) : null}
                                    <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Accountable owner
                                            </dt>
                                            <dd className="font-semibold">
                                                {ca.owner?.name ?? 'Unassigned'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Due date
                                            </dt>
                                            <dd>
                                                {ca.due_date
                                                    ? formatDateOnly(
                                                          ca.due_date,
                                                      )
                                                    : 'Not recorded'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Completed by
                                            </dt>
                                            <dd>
                                                {ca.completed_by_name ??
                                                    'Not recorded'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Verified by
                                            </dt>
                                            <dd>
                                                {ca.verified_by_name ??
                                                    'Not verified'}
                                            </dd>
                                        </div>
                                    </dl>
                                    {ca.recommendation ? (
                                        <section>
                                            <h3 className="text-sm font-semibold">
                                                Source recommendation
                                            </h3>
                                            <p className="mt-1 text-sm whitespace-pre-wrap">
                                                {ca.recommendation}
                                            </p>
                                        </section>
                                    ) : null}
                                    {ca.source.type === 'unavailable' ? (
                                        <p className="text-sm text-muted-foreground">
                                            The source task link needs review.
                                            Ask the H&S team to check the linked
                                            records.
                                        </p>
                                    ) : ca.source.type === 'restricted' ? (
                                        <p className="text-sm text-muted-foreground">
                                            The source operational task requires
                                            separate access.
                                        </p>
                                    ) : workspace.source_task_url ? (
                                        <Link
                                            className="frontline-tap inline-flex items-center font-medium text-primary"
                                            href={workspace.source_task_url}
                                        >
                                            Open source operational task
                                        </Link>
                                    ) : null}
                                    {ca.rework.latest_reason ? (
                                        <section>
                                            <h3 className="text-sm font-semibold">
                                                Changes requested
                                            </h3>
                                            <p className="mt-1 text-sm whitespace-pre-wrap">
                                                {ca.rework.latest_reason}
                                            </p>
                                        </section>
                                    ) : null}
                                    {ca.effectiveness_confirmed !== null ? (
                                        <p className="text-sm">
                                            Effectiveness assessment:{' '}
                                            {ca.effectiveness_confirmed
                                                ? 'Effective'
                                                : 'Not effective'}
                                        </p>
                                    ) : null}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card className="min-w-0 self-start">
                        <CardHeader>
                            <CardTitle>Next step</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm font-semibold">{status}</p>
                            {workspace.ownership.state ===
                                'replacement_needed' ||
                            workspace.ownership.state === 'unassigned' ? (
                                <p className="text-sm text-muted-foreground">
                                    {workspace.ownership.state ===
                                    'replacement_needed'
                                        ? 'The recorded owner needs replacement because their current role, employment or site access no longer supports this responsibility.'
                                        : 'Assign an accountable owner before starting this action.'}
                                </p>
                            ) : null}
                            {review.status === 'closed' &&
                            ca.status !== 'closed' ? (
                                <p className="text-sm text-muted-foreground">
                                    The safety review is closed. This action
                                    remains open until its own work and
                                    independent verification are finished.
                                </p>
                            ) : null}
                            {pane ? (
                                <p className="text-sm text-muted-foreground">
                                    Finish or cancel the current form before
                                    choosing another step.
                                </p>
                            ) : (
                                <>
                                    {workspace.ownership.can_assign ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            variant="outline"
                                            onClick={() =>
                                                select('overview', 'owner')
                                            }
                                        >
                                            {ca.owner
                                                ? 'Hand over ownership'
                                                : 'Assign owner'}
                                        </Button>
                                    ) : null}
                                    {can.start ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            disabled={command.processing}
                                            onClick={() =>
                                                draft.request(() =>
                                                    setConfirmation('start'),
                                                )
                                            }
                                        >
                                            Start action
                                        </Button>
                                    ) : null}
                                    {can.complete ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            onClick={() =>
                                                select('overview', 'complete')
                                            }
                                        >
                                            Mark complete
                                        </Button>
                                    ) : null}
                                    {can.verify ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            onClick={() =>
                                                select('overview', 'verify')
                                            }
                                        >
                                            <ShieldCheck className="mr-2 h-4 w-4" />
                                            Verify action
                                        </Button>
                                    ) : null}
                                    {can.return ? (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap w-full"
                                            onClick={() =>
                                                select('overview', 'return')
                                            }
                                        >
                                            Return for rework
                                        </Button>
                                    ) : null}
                                    {can.close ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            disabled={command.processing}
                                            onClick={() =>
                                                draft.request(() =>
                                                    setConfirmation('close'),
                                                )
                                            }
                                        >
                                            Close action
                                        </Button>
                                    ) : null}
                                    {!Object.values(can).some(Boolean) ? (
                                        <p className="text-sm text-muted-foreground">
                                            {ca.status === 'closed'
                                                ? 'This corrective action is closed.'
                                                : ca.status === 'completed'
                                                  ? 'An authorised person independent of the action must review the completion evidence.'
                                                  : 'The responsible H&S team manages the next step.'}
                                        </p>
                                    ) : null}
                                </>
                            )}
                            <Link
                                href={review.url}
                                className="frontline-tap inline-flex items-center text-sm font-medium text-primary"
                            >
                                Open safety review
                            </Link>
                            <p className="text-xs text-muted-foreground">
                                Completing this action does not close its
                                incident or safety review. Verification remains
                                a separate decision.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </SafetyFormDraftContext.Provider>
    );
}
