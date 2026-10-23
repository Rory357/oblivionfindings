import {
    APPROVAL_MODES,
    approvalModeLabel,
    historicalPolicyLabel,
    type ApprovalHistory,
    type ApprovalMode,
    type ApprovalSnapshot,
} from '@/components/health-safety/settings/approval-policy';
import { ApprovalPolicyDialog } from '@/components/health-safety/settings/approval-policy-dialog';
import { EvidenceWindowPanel } from '@/components/health-safety/settings/evidence-window-panel';
import type {
    EvidenceWindowHistory,
    EvidenceWindowOptions,
    EvidenceWindowSnapshot,
} from '@/components/health-safety/settings/evidence-window-policy';
import type {
    IndependenceHistory,
    IndependencePolicy,
} from '@/components/health-safety/settings/incident-independence';
import { IncidentIndependencePanel } from '@/components/health-safety/settings/incident-independence-panel';
import type {
    RoutingHistory,
    RoutingSnapshot,
} from '@/components/health-safety/settings/incident-routing';
import { IncidentRoutingPanel } from '@/components/health-safety/settings/incident-routing-panel';
import { PageHeader, PageLayout } from '@/components/page';
import { PageHeaderFacts, type PageHeaderFact } from '@/components/page/page-header-strip';
import {
    Choices,
    Journey,
    KnowList,
    Log,
    Note,
    QuietLine,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTimeLong, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { AlertCircle, Settings } from 'lucide-react';
import { useState } from 'react';

type View = 'approval' | 'history' | 'routing' | 'evidence';
const VIEWS: { key: View; label: string }[] = [
    { key: 'approval', label: 'Approval policy' },
    { key: 'history', label: 'Change history' },
    { key: 'routing', label: 'Incident routing' },
    { key: 'evidence', label: 'Evidence windows' },
];
const FLOW: Record<ApprovalMode, string[]> = {
    separate_specialist: ['Plan version', 'Specialist signs off', 'In use'],
    authorised_reviewer: ['Plan version', 'Reviewer decides', 'In use'],
};
const SHORT: Record<ApprovalMode, string> = {
    separate_specialist: 'Specialist sign-off',
    authorised_reviewer: 'Reviewer decides',
};
const ACTION_WORD = {
    application_default: 'Starting setting',
    changed: 'changed to',
    kept: 'kept',
    restored: 'restored',
} as const;

/** The approval mode a retained history value records, only when it is a valid policy value. */
function historicalMode(value: unknown): ApprovalMode | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const mode = (value as Record<string, unknown>).mode;
    return APPROVAL_MODES.some((option) => option.key === mode) ? (mode as ApprovalMode) : null;
}

/**
 * Health & safety settings, drawn as the approved settings drawings (24–24e): one banner with the four facts, the
 * sections down the left, and each setting as a picture — the choices side by side, where new incidents go,
 * evidence windows as time bars — with its editor one button away. Editing, drafts and history are unchanged.
 */
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
    const pageUrl = usePage().url;
    // A section can be opened from a link (?view=routing); unknown values open the approval policy.
    const [view, setView] = useState<View>(() => {
        const requested = new URLSearchParams(pageUrl.split('?')[1] ?? '').get('view');
        return VIEWS.some((item) => item.key === requested) ? (requested as View) : 'approval';
    });
    const [editing, setEditing] = useState(false);
    const drafts = useSafetyWorkspaceDrafts();
    const { auth } = usePage<SharedData>().props;
    const routingReadable = !!routing_configuration && routing_viewer_id === auth.user.id;
    const evidenceReadable = !!evidence_window_policy && evidence_window_viewer_id === auth.user.id;
    const lastChanged = [
        policy.reviewed_at,
        routingReadable ? routing_configuration?.recorded_at : null,
        independence_policy && independence_viewer_id === auth.user.id ? independence_policy.reviewed_at : null,
        evidenceReadable ? evidence_window_history?.data[0]?.recorded_at : null,
    ]
        .filter((value): value is string => !!value)
        .sort()
        .pop();
    const routeCount = routing_configuration?.configuration?.routes.length ?? 0;
    const ruleCount = evidence_window_policy?.policy_snapshot?.rules.length ?? 0;
    const facts: PageHeaderFact[] = [
        {
            value: policy.configuration_valid && policy.mode ? SHORT[policy.mode] : 'Needs review',
            label: 'Plan approval',
            tone: policy.configuration_valid ? undefined : 'warning',
        },
        {
            value: !routingReadable
                ? 'Unavailable'
                : routing_configuration?.state === 'configured'
                  ? `${routeCount} rule${routeCount === 1 ? '' : 's'}`
                  : routing_configuration?.state === 'repair_required'
                    ? 'Needs review'
                    : 'Not set',
            label: 'Incident routing',
            tone: routingReadable && routing_configuration?.state === 'repair_required' ? 'warning' : undefined,
        },
        {
            value: !evidenceReadable
                ? 'Unavailable'
                : evidence_window_policy?.configuration_state === 'configured'
                  ? `${ruleCount} rule${ruleCount === 1 ? '' : 's'}`
                  : evidence_window_policy?.configuration_state === 'disabled'
                    ? 'Off'
                    : evidence_window_policy?.configuration_state === 'invalid'
                      ? 'Needs review'
                      : 'Not set',
            label: 'Evidence windows',
            tone: evidenceReadable && evidence_window_policy?.configuration_state === 'invalid' ? 'warning' : undefined,
        },
        { value: lastChanged ? formatDate(lastChanged) : null, label: 'Last changed' },
    ];
    // When each choice came into use, from the retained history on this page (newest first): "In use since …",
    // "kept …", or for a former starting setting "The starting setting, used until …". Nothing when not recorded.
    const cameIntoUse = (mode: ApprovalMode) =>
        history.data.find(
            (entry) => entry.action !== 'kept' && historicalMode(entry.after_value) === mode,
        );
    const choiceMeta = (mode: ApprovalMode) => {
        const since = cameIntoUse(mode);
        if (policy.mode === mode) {
            if (!since) return undefined;
            const kept = history.data.find(
                (entry) =>
                    entry.action === 'kept' &&
                    historicalMode(entry.after_value) === mode &&
                    entry.recorded_at > since.recorded_at,
            );
            return (
                <>
                    In use since <b>{formatDate(since.recorded_at)}</b>
                    {kept ? ` · kept ${formatDate(kept.recorded_at)}` : ''}
                </>
            );
        }
        const current = policy.mode ? cameIntoUse(policy.mode) : undefined;
        return since?.action === 'application_default' && current
            ? `The starting setting, used until ${formatDate(current.recorded_at)}`
            : undefined;
    };
    // Oldest first, so the line reads from the starting setting to now.
    const timeline = [...history.data].reverse().slice(-6);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Settings', href: '/health-safety/settings' },
            ]}
        >
            <Head title="Health & safety settings" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            title="Health & safety settings"
                            icon={Settings}
                            subline="Who approves plans, where new incidents go and how long evidence is reviewed"
                            wrapTitle
                            facts={<PageHeaderFacts items={facts} />}
                        />
                    }
                >
                    <div className="grid items-start gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
                        <nav aria-label="Safety settings views" className="sg-surface grid gap-1 p-2">
                            {VIEWS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    aria-current={view === item.key ? 'page' : undefined}
                                    onClick={() => drafts.request(() => setView(item.key))}
                                    className={cn(
                                        'frontline-hit rounded-md px-3 py-2 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        view === item.key
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-foreground hover:bg-muted',
                                    )}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </nav>

                        <div className="flex min-w-0 flex-col gap-5">
                            {view === 'approval' ? (
                                <>
                                    {!policy.configuration_valid && (
                                        <Note tone="warning" icon={AlertCircle}>
                                            The stored policy needs review. New approvals remain blocked until a
                                            valid policy is recorded.
                                        </Note>
                                    )}
                                    <Choices
                                        title="How behaviour-support plans are approved"
                                        sub={
                                            policy.origin === 'organisation_setting'
                                                ? `Organisation decision · last decided ${formatDateTimeLong(policy.reviewed_at, 'not recorded')} · revision ${policy.policy_revision ?? 'unavailable'}`
                                                : policy.origin === 'application_default'
                                                  ? 'Application default; organisation review not yet recorded'
                                                  : 'Configuration invalid'
                                        }
                                        options={APPROVAL_MODES.map((option) => ({
                                            title: option.label,
                                            on: policy.mode === option.key,
                                            text: option.description,
                                            flow: FLOW[option.key],
                                            meta: choiceMeta(option.key),
                                        }))}
                                        actions={
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="frontline-hit"
                                                disabled={!can_manage}
                                                onClick={() => setEditing(true)}
                                            >
                                                <Settings className="size-4" aria-hidden />
                                                Review policy
                                            </Button>
                                        }
                                    />
                                    <KnowList
                                        title="Good to know"
                                        items={[
                                            { text: 'Each approval retains the exact plan version, applied policy, decision-maker, evidence and time.' },
                                            { text: 'Changing this setting does not rewrite historical decisions or approve existing plans.' },
                                            { tone: 'warning', text: 'Organisation designation does not establish professional qualification.' },
                                            { tone: 'success', text: 'Client and Site access, current authority, consent and clinical evidence remain part of their own workflows.' },
                                        ]}
                                    />
                                </>
                            ) : view === 'evidence' ? (
                                evidenceReadable && evidence_window_history && evidence_window_options && evidence_window_policy && evidence_window_viewer_id ? (
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
                                        Evidence-window settings are unavailable for your current account. Reload
                                        settings to check access.
                                    </p>
                                )
                            ) : view === 'routing' ? (
                                <>
                                    {routingReadable && routing_history && routing_sites && routing_configuration && routing_viewer_id ? (
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
                                        <p role="alert" className="text-subtle text-status-warning-foreground">
                                            Incident routing is unavailable for your current account. Reload the
                                            settings page to check current access.
                                        </p>
                                    )}
                                    {independence_policy && independence_history && independence_viewer_id === auth.user.id ? (
                                        <IncidentIndependencePanel
                                            key={independence_viewer_id}
                                            policy={independence_policy}
                                            history={independence_history}
                                            actorId={independence_viewer_id}
                                            canManage={!!can_manage_independence}
                                            discard={drafts.request}
                                        />
                                    ) : (
                                        <p role="alert" className="text-subtle text-status-warning-foreground">
                                            Independent review settings are unavailable for your current account.
                                            Reload the settings page to check access.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <section aria-label="Approval policy change history" className="flex flex-col gap-5">
                                    {timeline.length > 1 ? (
                                        <Journey
                                            title="How plan approval has changed"
                                            sub="Behaviour support plan approval"
                                            steps={timeline.map((entry) => {
                                                const mode = historicalMode(entry.after_value);
                                                return {
                                                    label:
                                                        entry.action === 'application_default'
                                                            ? 'Starting setting'
                                                            : entry.action === 'kept'
                                                              ? `Kept ${mode ? SHORT[mode].toLowerCase() : 'the policy'}`
                                                              : mode
                                                                ? SHORT[mode]
                                                                : 'Policy changed',
                                                    meta: `${formatDate(entry.recorded_at)} · ${entry.actor?.name ?? (entry.action === 'application_default' ? 'set by the app' : 'decision-maker not recorded')}`,
                                                    state: 'done' as const,
                                                };
                                            })}
                                        />
                                    ) : null}
                                    {history.data.length ? (
                                        <Log
                                            title="Change history"
                                            sub="Every saved change, back to the starting setting · NZ time"
                                            limit={50}
                                            items={history.data.map((entry) => {
                                                const mode = historicalMode(entry.after_value);
                                                const who =
                                                    entry.actor?.name ??
                                                    (entry.action === 'application_default'
                                                        ? 'Application default; no human reviewer'
                                                        : 'Decision-maker not recorded');
                                                return {
                                                    key: entry.id,
                                                    time: formatTime(entry.recorded_at),
                                                    tone: entry.action === 'application_default' ? 'none' : 'info',
                                                    title:
                                                        entry.action === 'application_default'
                                                            ? 'Starting setting'
                                                            : `${who} ${ACTION_WORD[entry.action]} ${mode ? approvalModeLabel(mode).toLowerCase() : historicalPolicyLabel(entry.after_value).toLowerCase()}`,
                                                    sub: [
                                                        `${formatDate(entry.recorded_at)} · revision ${entry.revision}`,
                                                        entry.action === 'changed' || entry.action === 'restored'
                                                            ? `Was: ${historicalPolicyLabel(entry.before_value).toLowerCase()}`
                                                            : null,
                                                        entry.reason ??
                                                            (entry.action === 'application_default'
                                                                ? 'Default captured on first use; no organisation approval is implied.'
                                                                : entry.action === 'kept'
                                                                  ? 'No reason recorded for keeping the workflow.'
                                                                  : 'Reason not recorded.'),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                };
                                            })}
                                        />
                                    ) : (
                                        <QuietLine title="Change history" text="No policy decisions are recorded." />
                                    )}
                                    <LaravelPagination
                                        links={history.links}
                                        lastPage={history.last_page}
                                        preserveScroll
                                        only={['history', 'policy', 'can_manage']}
                                    />
                                </section>
                            )}
                        </div>
                    </div>
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
