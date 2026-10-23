import {
    alertActions,
    primaryAlertAction,
    useAlertActions,
} from '@/components/control-room/alert-record/alert-actions';
import { AlertActionsMenu } from '@/components/control-room/alert-record/alert-actions-menu';
import {
    alertReference,
    alertSummary,
    severityBadge,
    statusBadge,
} from '@/components/control-room/alert-record/alert-labels';
import {
    alertTargets,
    nextOpenTarget,
    targetCaption,
    targetHeadline,
} from '@/components/control-room/alert-record/alert-targets';
import {
    ActivitySection,
    EvidenceSection,
    LinkedSection,
    OverviewSection,
    PlaybookSection,
    SlaTimelineSection,
    TasksSection,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-record/alert-sections';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    BookOpen,
    Clock,
    FileText,
    Link as LinkIcon,
    ListTodo,
    MessageSquare,
    Package,
    RadioTower,
    UserPlus,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

type RecordTab =
    | 'overview'
    | 'timeline'
    | 'procedure'
    | 'evidence'
    | 'tasks'
    | 'activity'
    | 'linked';

const TABS: ReadonlyArray<{
    key: RecordTab;
    label: string;
    icon: typeof FileText;
}> = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'timeline', label: 'Response & timeline', icon: Clock },
    { key: 'procedure', label: 'Procedure', icon: BookOpen },
    { key: 'evidence', label: 'Evidence', icon: Package },
    { key: 'tasks', label: 'Tasks', icon: ListTodo },
    { key: 'activity', label: 'Notes & activity', icon: MessageSquare },
    { key: 'linked', label: 'Linked records', icon: LinkIcon },
];

function initialTab(): RecordTab {
    if (typeof window === 'undefined') return 'overview';
    const tab = new URLSearchParams(window.location.search).get('tab');
    return TABS.some((t) => t.key === tab) ? (tab as RecordTab) : 'overview';
}

/** Keep the open tab in the address so a refresh or a shared link returns to it. */
function rememberTab(tab: RecordTab) {
    const url = new URL(window.location.href);
    if (tab === 'overview') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url.toString());
}

function isOpenTask(status: string) {
    return !['completed', 'cancelled', 'transferred'].includes(status);
}

function Panel({
    title,
    action,
    children,
}: {
    title?: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="min-w-0 gap-4 px-5 py-5">
            {title ? (
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-section-title">{title}</h2>
                    {action}
                </div>
            ) : null}
            {children}
        </Card>
    );
}

/**
 * The alert's own page — a permanent record with a usable URL (approved
 * scope, 11 Oct 2026; replaces the always-open workspace dialog). The
 * header carries the state, the response targets and the next step; the
 * tabs hold the record; every action opens its pop-up from the kit.
 */
export default function ControlRoomAlertShow(
    props: AlertWorkspaceDetail & { return_to: string },
) {
    const d = props;
    const a = d.alert;
    const ref = alertReference(a);
    const returnTo = props.return_to;
    const cameFromTasks = returnTo.startsWith('/tasks');
    const cameFromPeople = returnTo.startsWith('/operations/people-locations');
    const cameFromElsewhere = cameFromTasks || cameFromPeople;

    const [tab, setTab] = useState<RecordTab>(initialTab);
    const selectTab = (next: RecordTab) => {
        setTab(next);
        rememberTab(next);
    };

    const actions = useAlertActions(d);
    const primary = primaryAlertAction(d);
    const others = alertActions(d).filter((item) => item.key !== primary?.key);
    const assign = others.find((item) => item.key === 'assign');
    const more = others.filter((item) => item.key !== 'assign');

    const status = statusBadge(a.status);
    const severity = severityBadge(a.severity);
    const targets = alertTargets(d);
    const openTasks = d.tasks.filter((task) => isOpenTask(task.status)).length;
    const run = d.playbook_run;
    const runPercent = run?.total_steps
        ? Math.round((run.completed_steps / run.total_steps) * 100)
        : 0;
    const who = d.client?.name ?? a.asset?.name ?? null;

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Severity"
                tone={
                    a.severity === 'critical'
                        ? 'critical'
                        : a.severity === 'high'
                          ? 'warning'
                          : 'brand'
                }
                onClick={() => selectTab('overview')}
                ariaLabel="View the alert overview"
            >
                <PageHeaderMeterBig>
                    {severity.label.replace(/ severity$/, '')}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {a.escalation_level > 0
                        ? `Escalated to level ${a.escalation_level}`
                        : 'Not escalated'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {targets.map((target) => (
                <PageHeaderMeterBlock
                    key={target.key}
                    label={target.label}
                    tone={
                        target.state === 'missed'
                            ? 'critical'
                            : target.state === 'met'
                              ? 'success'
                              : 'brand'
                    }
                    onClick={() => selectTab('timeline')}
                    ariaLabel={`View the ${target.label.toLowerCase()} target`}
                >
                    <PageHeaderMeterBig>{targetHeadline(target)}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {targetCaption(target)}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ))}
            <PageHeaderMeterBlock
                label="Procedure"
                value={run ? `${run.completed_steps}/${run.total_steps}` : undefined}
                onClick={() => selectTab('procedure')}
                ariaLabel="View the response procedure"
            >
                <PageHeaderMeterBig>
                    {run ? `${runPercent}%` : 'Not started'}
                </PageHeaderMeterBig>
                {run ? <PageHeaderMeterBar percent={runPercent} /> : null}
                <PageHeaderMeterCaption>
                    {run ? run.playbook.name : 'No procedure on this alert'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Tasks"
                tone={openTasks > 0 ? 'warning' : 'brand'}
                onClick={() => selectTab('tasks')}
                ariaLabel="View the alert's tasks"
            >
                <PageHeaderMeterBig>{openTasks}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>open</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    const headerActions = (
        <>
            {assign ? (
                <PageHeaderGlassButton
                    icon={UserPlus}
                    onClick={() => actions.run('assign')}
                >
                    {assign.label}
                </PageHeaderGlassButton>
            ) : null}
            <AlertActionsMenu items={more} onRun={actions.run} />
            {primary ? (
                <PageHeaderPrimaryButton
                    icon={primary.icon}
                    onClick={() => actions.run(primary.key)}
                >
                    {primary.label}
                </PageHeaderPrimaryButton>
            ) : null}
        </>
    );

    const next = nextOpenTarget(targets);

    return (
        <ControlRoomPage
            headTitle={`Alert ${ref}`}
            breadcrumbs={
                cameFromElsewhere
                    ? [
                          { title: 'Home', href: '/dashboard' },
                          {
                              title: cameFromPeople ? 'People locations' : 'Tasks',
                              href: returnTo,
                          },
                          { title: ref },
                      ]
                    : undefined
            }
            recordCrumb={{ title: `Alert ${ref}` }}
            header={{
                variant: 'profile',
                backHref: returnTo,
                icon: RadioTower,
                title: alertSummary(a),
                wrapTitle: true,
                titleChip: (
                    <PageHeaderStatusChip variant={status.tone}>
                        {status.label}
                    </PageHeaderStatusChip>
                ),
                subline: [ref, who, d.journey_state].filter(Boolean).join(' · '),
                actions: headerActions,
                meters,
            }}
            rail={
                <PageHeaderRail
                    ariaLabel="Alert record"
                    value={tab}
                    onSelect={selectTab}
                    items={TABS.map((t) => ({
                        key: t.key,
                        label: t.label,
                        icon: t.icon,
                        count:
                            t.key === 'tasks' && openTasks > 0
                                ? openTasks
                                : undefined,
                    }))}
                />
            }
        >
            {tab === 'overview' ? (
                <div className="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <Panel title="What needs to happen">
                        <OverviewSection
                            d={d}
                            onEditMeta={() => actions.run('edit_details')}
                            onConfirmSensor={() => actions.run('confirm_sensor')}
                            onCreateIncident={() =>
                                actions.run('report_incident')
                            }
                        />
                    </Panel>
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel title="Owner and next step">
                            <dl className="grid gap-3 text-sm">
                                <div>
                                    <dt className="text-caption">Owner</dt>
                                    <dd className="font-medium">
                                        {a.assigned_to?.name ?? 'Unassigned'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-caption">Next step</dt>
                                    <dd className="font-medium">
                                        {primary?.label ?? 'No response step left'}
                                    </dd>
                                </div>
                                {next ? (
                                    <div>
                                        <dt className="text-caption">
                                            {next.label} target
                                        </dt>
                                        <dd
                                            className={
                                                next.state === 'missed'
                                                    ? 'font-medium text-status-critical'
                                                    : 'font-medium'
                                            }
                                        >
                                            {targetHeadline(next)} ·{' '}
                                            {targetCaption(next)}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>
                            {primary || assign ? (
                                <div className="flex flex-wrap gap-2">
                                    {primary ? (
                                        <Button
                                            type="button"
                                            onClick={() => actions.run(primary.key)}
                                        >
                                            {primary.label}
                                        </Button>
                                    ) : null}
                                    {assign ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => actions.run('assign')}
                                        >
                                            {assign.label}
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                        </Panel>
                        <Panel title="Watching">
                            {d.watchers.length ? (
                                <ul className="grid gap-1.5 text-sm">
                                    {d.watchers.map((w) => (
                                        <li key={w.id}>{w.user_name}</li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-subtle">
                                    No one is watching this alert yet.
                                </p>
                            )}
                            {d.can.watch ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="justify-self-start"
                                    onClick={() => actions.run('watch')}
                                >
                                    {d.is_watching
                                        ? 'Stop watching'
                                        : 'Watch this alert'}
                                </Button>
                            ) : null}
                        </Panel>
                    </div>
                </div>
            ) : null}
            {tab === 'timeline' ? (
                <Panel>
                    <SlaTimelineSection d={d} />
                </Panel>
            ) : null}
            {tab === 'procedure' ? (
                <Panel>
                    <PlaybookSection
                        d={d}
                        onStart={() => actions.run('start_procedure')}
                    />
                </Panel>
            ) : null}
            {tab === 'evidence' ? (
                <Panel>
                    <EvidenceSection d={d} />
                </Panel>
            ) : null}
            {tab === 'tasks' ? (
                <Panel>
                    <TasksSection d={d} />
                </Panel>
            ) : null}
            {tab === 'activity' ? (
                <Panel>
                    <ActivitySection d={d} />
                </Panel>
            ) : null}
            {tab === 'linked' ? (
                <Panel>
                    <LinkedSection
                        d={d}
                        onHandoff={() => actions.run('it_handoff')}
                    />
                </Panel>
            ) : null}
            {actions.host}
        </ControlRoomPage>
    );
}
