import {
    approvalModeLabel,
    historicalPolicyLabel,
    type ApprovalHistory,
    type ApprovalSnapshot,
} from '@/components/health-safety/settings/approval-policy';
import { ApprovalPolicyDialog } from '@/components/health-safety/settings/approval-policy-dialog';
import {
    independenceModeLabel,
    type IndependenceHistory,
    type IndependencePolicy,
} from '@/components/health-safety/settings/incident-independence';
import { IncidentIndependencePanel } from '@/components/health-safety/settings/incident-independence-panel';
import type {
    RoutingHistory,
    RoutingSnapshot,
} from '@/components/health-safety/settings/incident-routing';
import { IncidentRoutingPanel } from '@/components/health-safety/settings/incident-routing-panel';
import { EvidenceWindowPanel } from '@/components/health-safety/settings/evidence-window-panel';
import type { EvidenceWindowHistory, EvidenceWindowOptions, EvidenceWindowSnapshot } from '@/components/health-safety/settings/evidence-window-policy';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    Camera,
    ClipboardCheck,
    History,
    Settings,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

const VIEWS = [
    { key: 'approval' as const, label: 'Approval policy', icon: ShieldCheck },
    { key: 'history' as const, label: 'Change history', icon: History },
    { key: 'routing' as const, label: 'Incident routing', icon: Settings },
    { key: 'evidence' as const, label: 'Evidence windows', icon: Camera },
];
export default function HealthSafetySettings({
    policy,
    can_manage,
    history,
    routing_configuration,
    routing_history,
    routing_sites,
    can_manage_routing,
    routing_viewer_id,
    independence_policy,
    independence_history,
    can_manage_independence,
    independence_viewer_id,
    evidence_window_policy,
    evidence_window_history,
    evidence_window_options,
    can_manage_evidence_windows,
    evidence_window_viewer_id,
}: {
    policy: ApprovalSnapshot;
    can_manage: boolean;
    history: ApprovalHistory;
    routing_configuration?: RoutingSnapshot;
    routing_history?: RoutingHistory;
    routing_sites?: Array<{ id: number; name: string }>;
    can_manage_routing?: boolean;
    routing_viewer_id?: number;
    independence_policy?: IndependencePolicy;
    independence_history?: IndependenceHistory;
    can_manage_independence?: boolean;
    independence_viewer_id?: number;
    evidence_window_policy?: EvidenceWindowSnapshot;
    evidence_window_history?: EvidenceWindowHistory;
    evidence_window_options?: EvidenceWindowOptions;
    can_manage_evidence_windows?: boolean;
    evidence_window_viewer_id?: number;
}) {
    const [view, setView] = useState<'approval' | 'history' | 'routing' | 'evidence'>(
        'approval',
    );
    const [editing, setEditing] = useState(false);
    const drafts = useSafetyWorkspaceDrafts();
    const { auth } = usePage<SharedData>().props;
    const canOpenRegisters = !!(
        auth.can?.hazards?.view ||
        auth.can?.restraints?.view ||
        auth.can?.hr?.wellbeing?.view
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(canOpenRegisters
                    ? [
                          {
                              title: 'Safety registers',
                              href: '/health-safety/registers',
                          },
                      ]
                    : []),
                {
                    title: 'Health & Safety settings',
                    href: '/health-safety/settings',
                },
            ]}
        >
            <Head title="Health & Safety settings" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            title="Health & Safety settings"
                            icon={Settings}
                            subline="Organisation approvals, incident routing, evidence windows and retained decisions"
                            wrapTitle
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Policy revision"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('approval'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {policy.policy_revision ??
                                                'Unavailable'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {policy.configuration_valid
                                                ? 'Current policy'
                                                : 'Configuration needs review'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Approval workflow"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('approval'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {policy.mode ===
                                            'separate_specialist'
                                                ? 'Specialist'
                                                : policy.mode ===
                                                    'authorised_reviewer'
                                                  ? 'Reviewer'
                                                  : 'Unavailable'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Explicit decision for each plan
                                            version
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Organisation review"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('history'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {policy.reviewed_at
                                                ? 'Recorded'
                                                : 'Not recorded'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {policy.reviewed_at
                                                ? formatDateTimeLong(
                                                      policy.reviewed_at,
                                                  )
                                                : 'Application default has no reviewer'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="History entries"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('history'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {history.total}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Default provenance and saved
                                            decisions
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Incident routing"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('routing'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {routing_configuration &&
                                            routing_viewer_id === auth.user.id
                                                ? routing_configuration.state ===
                                                  'configured'
                                                    ? 'Recorded'
                                                    : routing_configuration.state ===
                                                        'repair_required'
                                                      ? 'Needs review'
                                                      : 'Not set'
                                                : 'Unavailable'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Current eligibility is checked per
                                            incident
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Independent review"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setView('routing'),
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {independence_policy &&
                                            independence_viewer_id ===
                                                auth.user.id
                                                ? independence_policy.mode ===
                                                  'block_involved_access'
                                                    ? 'Restricted'
                                                    : independence_policy.mode ===
                                                        'assignment_only'
                                                      ? 'Assignment only'
                                                      : 'Needs review'
                                                : 'Unavailable'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {independence_policy &&
                                            independence_viewer_id ===
                                                auth.user.id
                                                ? independenceModeLabel(
                                                      independence_policy.mode,
                                                  )
                                                : 'Current policy unavailable'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={VIEWS}
                                    value={view}
                                    onSelect={(next) =>
                                        drafts.request(() => setView(next))
                                    }
                                    ariaLabel="Safety settings views"
                                />
                            }
                        />
                    }
                >
                    {view === 'approval' ? (
                        <Card>
                            <CardContent className="space-y-5 p-5 sm:p-6">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="space-y-2">
                                        <h2 className="text-section-title">
                                            Behaviour support plan approval
                                        </h2>
                                        <StatusBadge
                                            variant={
                                                policy.configuration_valid
                                                    ? 'info'
                                                    : 'warning'
                                            }
                                        >
                                            {policy.configuration_valid ? (
                                                <ShieldCheck
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                            ) : (
                                                <AlertCircle
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                            )}
                                            {approvalModeLabel(policy.mode)}
                                        </StatusBadge>
                                    </div>
                                    <Button
                                        size="sm"
                                        className="frontline-hit"
                                        disabled={!can_manage}
                                        onClick={() => setEditing(true)}
                                    >
                                        <Settings
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Review policy
                                    </Button>
                                </div>
                                {!policy.configuration_valid && (
                                    <p
                                        role="alert"
                                        className="text-subtle rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-status-warning-foreground"
                                    >
                                        The stored policy needs review. New
                                        approvals remain blocked until a valid
                                        policy is recorded.
                                    </p>
                                )}
                                <dl className="text-subtle grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Current workflow
                                        </dt>
                                        <dd className="mt-1 font-medium">
                                            {approvalModeLabel(policy.mode)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Policy revision
                                        </dt>
                                        <dd className="mt-1 font-medium">
                                            {policy.policy_revision ??
                                                'Unavailable'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Policy source
                                        </dt>
                                        <dd className="mt-1">
                                            {policy.origin ===
                                            'application_default'
                                                ? 'Application default; organisation review not yet recorded'
                                                : policy.origin ===
                                                    'organisation_setting'
                                                  ? 'Recorded organisation decision'
                                                  : 'Configuration invalid'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Latest organisation decision
                                        </dt>
                                        <dd className="mt-1">
                                            {formatDateTimeLong(
                                                policy.reviewed_at,
                                                'Not recorded',
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="text-subtle">
                                    {policy.mode === 'separate_specialist'
                                        ? 'A designated approver records a separate specialist decision for the exact plan version. Ordinary plan review does not approve it.'
                                        : policy.mode === 'authorised_reviewer'
                                          ? 'An authorised plan reviewer or manager records an explicit approval for the exact plan version. Separate specialist permission is not required.'
                                          : 'Review the approval workflow before a new plan decision can be accepted.'}
                                </p>
                                <div className="text-subtle space-y-2 border-t border-border pt-4 text-muted-foreground">
                                    <p>
                                        Each approval retains the exact plan
                                        version, applied policy, decision-maker,
                                        evidence and time. Changing this setting
                                        does not rewrite historical decisions or
                                        approve existing plans.
                                    </p>
                                    <p>
                                        Organisation designation does not
                                        establish professional qualification.
                                        Client and Site access, current
                                        authority, consent and clinical evidence
                                        remain part of their own workflows.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    ) : view === 'evidence' ? (
                        evidence_window_policy && evidence_window_history && evidence_window_options &&
                        evidence_window_viewer_id === auth.user.id ? (
                            <EvidenceWindowPanel
                                policy={evidence_window_policy}
                                history={evidence_window_history}
                                options={evidence_window_options}
                                actorId={evidence_window_viewer_id}
                                canManage={!!can_manage_evidence_windows}
                                discard={drafts.request}
                            />
                        ) : (
                            <p role="alert" className="text-subtle text-status-warning-foreground">
                                Evidence-window settings are unavailable for your current account. Reload settings to check access.
                            </p>
                        )
                    ) : view === 'routing' ? (
                        <div className="space-y-6">
                            {independence_policy &&
                            independence_history &&
                            independence_viewer_id === auth.user.id ? (
                                <IncidentIndependencePanel
                                    key={independence_viewer_id}
                                    policy={independence_policy}
                                    history={independence_history}
                                    actorId={independence_viewer_id}
                                    canManage={!!can_manage_independence}
                                    discard={drafts.request}
                                />
                            ) : (
                                <p
                                    role="alert"
                                    className="text-subtle text-status-warning-foreground"
                                >
                                    Independent review settings are unavailable
                                    for your current account. Reload the
                                    settings page to check access.
                                </p>
                            )}
                            {routing_configuration &&
                            routing_history &&
                            routing_sites &&
                            routing_viewer_id === auth.user.id ? (
                                <IncidentRoutingPanel
                                    key={routing_viewer_id}
                                    snapshot={routing_configuration}
                                    history={routing_history}
                                    sites={routing_sites}
                                    canManage={!!can_manage_routing}
                                    actorId={routing_viewer_id}
                                    discard={drafts.request}
                                />
                            ) : (
                                <p
                                    role="alert"
                                    className="text-subtle text-status-warning-foreground"
                                >
                                    Incident routing is unavailable for your
                                    current account. Reload the settings page to
                                    check current access.
                                </p>
                            )}
                        </div>
                    ) : (
                        <section
                            aria-label="Approval policy change history"
                            className="space-y-4"
                        >
                            <div>
                                <h2 className="text-section-title">
                                    Change history
                                </h2>
                                <p className="text-subtle mt-1 text-muted-foreground">
                                    Saved decisions and the original application
                                    default. Times use Pacific/Auckland.
                                </p>
                            </div>
                            {history.data.length ? (
                                <ul className="space-y-3">
                                    {history.data.map((entry) => (
                                        <li key={entry.id}>
                                            <Card>
                                                <CardContent className="space-y-3 p-5">
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <h3 className="text-section-title">
                                                            Revision{' '}
                                                            {entry.revision} ·{' '}
                                                            {entry.action ===
                                                            'application_default'
                                                                ? 'Application default recorded'
                                                                : entry.action ===
                                                                    'kept'
                                                                  ? 'Workflow kept'
                                                                  : entry.action ===
                                                                      'restored'
                                                                    ? 'Workflow restored'
                                                                    : 'Workflow changed'}
                                                        </h3>
                                                        <StatusBadge variant="neutral">
                                                            <ClipboardCheck
                                                                className="size-3"
                                                                aria-hidden
                                                            />
                                                            {entry.origin ===
                                                            'application_default'
                                                                ? 'Default provenance'
                                                                : 'Organisation decision'}
                                                        </StatusBadge>
                                                    </div>
                                                    <p className="text-caption text-muted-foreground">
                                                        {formatDateTimeLong(
                                                            entry.recorded_at,
                                                        )}{' '}
                                                        ·{' '}
                                                        {entry.actor?.name ??
                                                            (entry.action ===
                                                            'application_default'
                                                                ? 'Application default; no human reviewer'
                                                                : 'Decision-maker not recorded')}
                                                    </p>
                                                    <dl className="text-subtle grid gap-3 sm:grid-cols-2">
                                                        <div>
                                                            <dt className="text-muted-foreground">
                                                                Previous policy
                                                            </dt>
                                                            <dd>
                                                                {historicalPolicyLabel(
                                                                    entry.before_value,
                                                                )}
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt className="text-muted-foreground">
                                                                Recorded policy
                                                            </dt>
                                                            <dd>
                                                                {historicalPolicyLabel(
                                                                    entry.after_value,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                    <p className="text-subtle break-words whitespace-pre-wrap">
                                                        {entry.reason ??
                                                            (entry.action ===
                                                            'application_default'
                                                                ? 'Default captured on first use; no organisation approval is implied.'
                                                                : entry.action ===
                                                                    'kept'
                                                                  ? 'No reason recorded for keeping the workflow.'
                                                                  : 'Reason not recorded.')}
                                                    </p>
                                                </CardContent>
                                            </Card>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-subtle text-muted-foreground">
                                    No policy decisions are recorded.
                                </p>
                            )}
                            <LaravelPagination
                                links={history.links}
                                lastPage={history.last_page}
                                preserveScroll
                                only={['history', 'policy', 'can_manage']}
                            />
                        </section>
                    )}
                </PageLayout>
                {editing && (
                    <ApprovalPolicyDialog
                        policy={policy}
                        canManage={can_manage}
                        onClose={() => drafts.request(() => setEditing(false))}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
