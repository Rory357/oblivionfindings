import {
    ACTION_META,
    FollowupsSection,
    InvestigationSection,
    LinkedSection,
    OverviewSection,
    PhotosSection,
    TimelineSection,
    type IncidentDetail,
    type LifecycleAction,
} from '@/components/incidents/incident-detail-dialog';
import { IncidentOwnershipPanel } from '@/components/incidents/incident-ownership-panel';
import { RecordingWindowEditor } from '@/components/incidents/recording-window-editor';
import { RecordingReferenceChooser } from '@/components/incidents/recording-reference-chooser';
import type { RecordingPreview } from '@/components/incidents/recording-window-command';
import { IncidentRecordActionDialog } from '@/components/incidents/incident-record-action-dialog';
import { IncidentResponseRequestDialog } from '@/components/incidents/incident-response-request-dialog';
import { JourneyGateList } from '@/components/incidents/journey-gate-list';
import {
    RecordingReferenceEvidence,
    type RecordingReferenceEvidenceData,
} from '@/components/incidents/recording-reference-evidence';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { ReviewRow } from '@/components/wizard/shell';
import { useIncidentWorkspaceDrafts } from '@/hooks/use-incident-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { incidentRegisterReturn, incidentReturnLabel } from '@/lib/incidents/navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    FileText,
    LinkIcon,
    ListTodo,
    Paperclip,
    Pencil,
    RadioTower,
    Search,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Section =
    | 'overview'
    | 'followups'
    | 'evidence'
    | 'investigation'
    | 'linked'
    | 'history';
type IncidentWorkspaceDetail = IncidentDetail & {
    recording_evidence?: RecordingReferenceEvidenceData;
    recording_preview?: RecordingPreview;
};
const SECTIONS: PageHeaderRailItem<Section>[] = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'followups', label: 'Follow-ups', icon: ListTodo },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'investigation', label: 'Investigation', icon: Search },
    { key: 'linked', label: 'Linked records', icon: LinkIcon },
    { key: 'history', label: 'History', icon: Clock },
];

function label(value: string): string {
    const text = value.replaceAll('_', ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Permanent record workspace; only bounded edits and decisions open a dialog. */
export default function IncidentShow({
    detail,
    viewer_id,
}: {
    detail: IncidentWorkspaceDetail | null;
    viewer_id: number;
}) {
    const { props, url } = usePage<SharedData>();
    if (
        !detail ||
        !Number.isSafeInteger(viewer_id) ||
        viewer_id !== props.auth?.user?.id
    ) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Health & Safety', href: '/health-safety' },
                    { title: 'Incidents', href: '/incidents' },
                ]}
            >
                <Head title="Incident" />
                <PageLayout
                    hero={
                        <PageHeader
                            title="Incident"
                            icon={AlertTriangle}
                            frontline
                            wrapTitle
                            subline="Incident information is unavailable for your current account."
                            actions={
                                <PageHeaderPrimaryButton
                                    onClick={() =>
                                        router.get(
                                            url,
                                            {},
                                            { preserveState: false },
                                        )
                                    }
                                >
                                    Reload incident
                                </PageHeaderPrimaryButton>
                            }
                        />
                    }
                >
                    <p
                        role="alert"
                        className="text-subtle text-status-warning-foreground"
                    >
                        Reload to check current access before opening incident
                        information or taking action.
                    </p>
                </PageLayout>
            </AppLayout>
        );
    }
    return (
        <IncidentWorkspace key={`${viewer_id}:${detail.id}`} detail={detail} />
    );
}

export function IncidentWorkspace({ detail: d }: { detail: IncidentWorkspaceDetail }) {
    const drafts = useIncidentWorkspaceDrafts(d.id);
    const { url, props } = usePage<SharedData>();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const requested = params.get('section');
    const returnTo = incidentRegisterReturn(params.get('return_to'));
    const section =
        SECTIONS.find((item) => item.key === requested)?.key ?? 'overview';
    const [action, setAction] = useState<LifecycleAction | 'edit' | null>(null);
    const [requestResponse, setRequestResponse] = useState(false);
    const [findOpen, setFindOpen] = useState(false);
    const [recordingsLoading, setRecordingsLoading] = useState(false);
    const recordingVisitPending = useRef(false);
    const rememberedSections = useRef<Record<string, Section>>({});
    const openFind = useCallback(() => {
        if (!action && !requestResponse) setFindOpen(true);
    }, [action, requestResponse]);
    useGroupedProfileSearchShortcut(openFind);
    const reference = d.ref ?? `INC-${d.id}`;
    const person = d.client
        ? `${d.client.first_name} ${d.client.last_name}`.trim()
        : 'No person linked';
    const openFollowups = d.followups.filter(
        (followup) => !followup.completed_at,
    ).length;
    const outstandingSafetyActions = d.hs_event
        ? d.hs_event.corrective_actions.filter(
              (item) => !['verified', 'closed'].includes(item.status),
          ).length
        : d.linked_access?.health_safety === 'absent'
          ? 0
          : null;
    const remainingChecks = d.close_gate.requirements.filter(
        (item) => !item.complete,
    ).length;
    const recordAction: LifecycleAction | null =
        d.status === 'draft' && d.can.submit
            ? 'submit'
            : d.status === 'submitted' && d.can.review
              ? 'review'
              : d.status === 'reviewed' && d.can.close
                ? 'close'
                : d.status === 'closed' && d.can.reopen
                  ? 'reopen'
                  : null;
    const responseActive = d.journey_state === 'Operational response active';
    const responseHref = responseActive ? d.control_room_alert?.url : null;
    const canEditDraft = d.can.update && d.status === 'draft';
    const headerAction = responseHref
        ? 'response'
        : recordAction
          ? 'record'
          : canEditDraft
            ? 'edit'
            : d.can.requestResponse
              ? 'request'
              : null;
    const bodyOrder =
        section !== 'overview'
            ? ['details', 'actions', 'summary']
            : responseActive
              ? ['actions', 'summary', 'details']
              : ['summary', 'actions', 'details'];
    const onSection = (next: Section) =>
        router.get(
            `/incidents/${d.id}`,
            {
                section: next,
                ...(returnTo !== '/incidents' ? { return_to: returnTo } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    const onRecordingPageChange = (page: number) => {
        if (recordingVisitPending.current || !Number.isSafeInteger(page) || page < 1 || page > 100000) return;
        recordingVisitPending.current = true;
        setRecordingsLoading(true);
        // Same-record visits retain the mounted follow-up/action drafts under the existing guard.
        router.get(
            `/incidents/${d.id}`,
            {
                section: 'evidence',
                recording_page: page,
                ...(returnTo !== '/incidents' ? { return_to: returnTo } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['detail', 'viewer_id'],
                onFinish: () => {
                    recordingVisitPending.current = false;
                    setRecordingsLoading(false);
                },
            },
        );
    };
    const completeFollowup = (id: number) =>
        router.post(
            `/incidents/${d.id}/followups/${id}/complete`,
            {},
            { preserveScroll: true },
        );
    const sections = SECTIONS.map((item) => ({
        ...item,
        ...(item.key === 'followups' ? { count: openFollowups } : {}),
        ...(item.key === 'evidence' ? { count: d.attachments.length } : {}),
        ...(item.key === 'investigation' && outstandingSafetyActions !== null
            ? { count: outstandingSafetyActions }
            : {}),
    }));
    const groups: GroupedProfileNavGroup[] = [
        { key: 'incident', label: 'Incident', icon: FileText, tabs: [] },
        { key: 'review', label: 'Safety review', icon: Search, tabs: [] },
        {
            key: 'records',
            label: 'Evidence & history',
            icon: Paperclip,
            tabs: [],
        },
    ];
    for (const item of sections) {
        const group =
            item.key === 'overview' || item.key === 'followups'
                ? groups[0]
                : item.key === 'investigation' || item.key === 'linked'
                  ? groups[1]
                  : groups[2];
        group.tabs.push({ ...item, icon: item.icon ?? FileText });
    }
    const activeGroup =
        groups.find((group) =>
            group.tabs.some((item) => item.key === section),
        ) ?? groups[0];
    useEffect(() => {
        rememberedSections.current[activeGroup.key] = section;
    }, [activeGroup.key, section]);
    const selectSection = (key: string) => {
        const next = SECTIONS.find((item) => item.key === key);
        if (next) onSection(next.key);
    };
    const selectGroup = (key: string) => {
        const group = groups.find((item) => item.key === key);
        if (!group || group.key === activeGroup.key) return;
        const remembered = rememberedSections.current[group.key];
        selectSection(
            group.tabs.some((item) => item.key === remembered)
                ? remembered
                : group.tabs[0].key,
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                { title: incidentReturnLabel(returnTo), href: returnTo },
                { title: reference, href: `/incidents/${d.id}` },
            ]}
        >
            <Head title={`Incident ${reference}`} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        backHref={returnTo}
                        icon={AlertTriangle}
                        title={reference}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    d.status === 'closed'
                                        ? 'neutral'
                                        : d.status === 'draft'
                                          ? 'warning'
                                          : 'info'
                                }
                            >
                                {label(d.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={`${label(d.type)} · ${person} · ${d.occurred_at ? formatDateTime(d.occurred_at) : 'Occurrence time not recorded'} · ${label(d.severity)} severity`}
                        search={
                            <PageHeaderSearchTrigger
                                placeholder="Find an incident section…"
                                onOpen={openFind}
                            />
                        }
                        actions={
                            headerAction ? (
                                <>
                                    {headerAction === 'response' && responseHref ? (
                                        <PageHeaderPrimaryButton
                                            asChild
                                            icon={RadioTower}
                                        >
                                            <Link href={responseHref}>
                                                Open response
                                            </Link>
                                        </PageHeaderPrimaryButton>
                                    ) : headerAction === 'record' && recordAction ? (
                                        <PageHeaderPrimaryButton
                                            onClick={() => setAction(recordAction)}
                                            disabled={
                                                recordAction === 'close' &&
                                                !d.close_gate.allowed
                                            }
                                        >
                                            {ACTION_META[recordAction].title}
                                        </PageHeaderPrimaryButton>
                                    ) : headerAction === 'edit' ? (
                                        <PageHeaderPrimaryButton
                                            icon={Pencil}
                                            onClick={() => setAction('edit')}
                                        >
                                            Edit draft
                                        </PageHeaderPrimaryButton>
                                    ) : headerAction === 'request' ? (
                                        <PageHeaderPrimaryButton
                                            icon={RadioTower}
                                            onClick={() => setRequestResponse(true)}
                                        >
                                            Request Control Room response
                                        </PageHeaderPrimaryButton>
                                    ) : null}
                                    {canEditDraft && headerAction !== 'edit' ? (
                                        <PageHeaderGlassButton
                                            icon={Pencil}
                                            onClick={() => setAction('edit')}
                                        >
                                            Edit draft
                                        </PageHeaderGlassButton>
                                    ) : null}
                                </>
                            ) : undefined
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Open follow-ups"
                                    onClick={() => onSection('followups')}
                                    pressed={section === 'followups'}
                                    tone={
                                        openFollowups > 0 ? 'warning' : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {openFollowups}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Incident follow-ups to finish
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence"
                                    onClick={() => onSection('evidence')}
                                    pressed={section === 'evidence'}
                                >
                                    <PageHeaderMeterBig>
                                        {d.attachments.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Incident attachments
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Safety actions"
                                    onClick={() => onSection('investigation')}
                                    pressed={section === 'investigation'}
                                    tone={
                                        outstandingSafetyActions !== null &&
                                        outstandingSafetyActions > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {outstandingSafetyActions ?? '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {outstandingSafetyActions === null
                                            ? d.linked_access?.health_safety ===
                                              'restricted'
                                                ? 'Safety review access restricted'
                                                : 'Safety review status unavailable'
                                            : d.hs_event
                                              ? 'Actions still awaiting verification'
                                              : 'No linked safety review'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Closure checks"
                                    onClick={() => onSection('overview')}
                                    pressed={section === 'overview'}
                                    tone={
                                        remainingChecks > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {remainingChecks}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded checks still outstanding
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={groups.map((group) => ({
                                    key: group.key,
                                    label: group.label,
                                    icon: group.icon,
                                    count: group.tabs.some(
                                        (item) => item.count !== undefined,
                                    )
                                        ? group.tabs.reduce(
                                              (sum, item) =>
                                                  sum + (item.count ?? 0),
                                              0,
                                          )
                                        : undefined,
                                }))}
                                value={activeGroup.key}
                                onSelect={selectGroup}
                                onFind={openFind}
                                ariaLabel="Incident groups"
                            />
                        }
                    />
                }
                tabs={
                    <TierTwoTabs
                        tabs={activeGroup.tabs}
                        activeTab={section}
                        onTab={selectSection}
                        testIdPrefix="incident-workspace"
                        ariaLabel="Incident sections"
                        panelId="incident-workspace-panel"
                        renderLink={(tab, className, inner, accessibility) => (
                            <button
                                type="button"
                                className={className}
                                {...accessibility}
                                onClick={() => selectSection(tab.key)}
                            >
                                {inner}
                            </button>
                        )}
                    />
                }
            >
                <div
                    id="incident-workspace-panel"
                    role="tabpanel"
                    aria-labelledby={`incident-workspace-tab-${section}`}
                    className="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]"
                >
                    {[
                        <section
                            key="summary"
                            hidden={section !== 'overview'}
                            aria-label="Incident summary"
                            className="min-w-0 xl:col-start-1 xl:row-start-1"
                        >
                            <OverviewSection
                                d={d}
                                isNearMiss={d.type === 'near_miss'}
                                part="summary"
                            />
                        </section>,
                        <aside
                            key="actions"
                            className="space-y-5 xl:col-start-2 xl:row-span-2 xl:row-start-1"
                            aria-label="Incident next step and responsibilities"
                        >
                            <Card>
                                <CardHeader>
                                    <h2 className="text-section-title">
                                        Next step
                                    </h2>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <p className="text-sm font-semibold">
                                        {d.journey_state}
                                    </p>
                                    {responseActive ? (
                                        <div className="space-y-3">
                                            <p className="text-sm text-muted-foreground">
                                                The operational response is
                                                still active. Response and
                                                incident review are separate
                                                decisions.
                                            </p>
                                            {!responseHref ? (
                                                <p className="text-sm text-muted-foreground">
                                                    A permitted Control Room
                                                    responder must continue the
                                                    response.
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                    {d.can.requestResponse &&
                                    headerAction !== 'request' ? (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap w-full"
                                            onClick={() =>
                                                setRequestResponse(true)
                                            }
                                        >
                                            <RadioTower className="size-4" />
                                            Request Control Room response
                                        </Button>
                                    ) : null}
                                    {recordAction && headerAction !== 'record' ? (
                                        <Button
                                            className="frontline-tap w-full"
                                            variant={
                                                responseActive
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            onClick={() =>
                                                setAction(recordAction)
                                            }
                                            disabled={
                                                recordAction === 'close' &&
                                                !d.close_gate.allowed
                                            }
                                        >
                                            {ACTION_META[recordAction].title}
                                        </Button>
                                    ) : !recordAction ? (
                                        <p className="text-sm text-muted-foreground">
                                            {d.journey_state ===
                                            'Journey closed'
                                                ? 'The connected journey is closed.'
                                                : 'No incident transition is available for your role at this stage.'}
                                        </p>
                                    ) : null}
                                    {d.status !== 'closed' &&
                                    d.close_gate.requirements.length > 0 ? (
                                        <details
                                            open={recordAction === 'close'}
                                        >
                                            <summary className="frontline-tap frontline-focus flex cursor-pointer items-center rounded-md text-sm font-medium">
                                                {d.close_gate.allowed
                                                    ? 'Closure requirements complete'
                                                    : `${d.close_gate.requirements.filter((requirement) => !requirement.complete).length} requirements remain before closure`}
                                            </summary>
                                            <JourneyGateList
                                                gate={d.close_gate}
                                            />
                                        </details>
                                    ) : null}
                                    {d.status === 'closed' &&
                                    d.journey_state !== 'Journey closed' ? (
                                        <p className="text-sm text-muted-foreground">
                                            The incident report is closed.
                                            Linked response or safety work still
                                            has its own completion requirements.
                                        </p>
                                    ) : null}
                                </CardContent>
                            </Card>
                            {d.ownership ? (
                                <IncidentOwnershipPanel
                                    incidentId={d.id}
                                    reference={reference}
                                    ownership={d.ownership}
                                />
                            ) : null}
                            <Card>
                                <CardHeader>
                                    <h2 className="text-section-title flex items-center gap-2">
                                        <Users className="size-4 text-primary" />
                                        Responsibilities
                                    </h2>
                                </CardHeader>
                                <CardContent>
                                    <ReviewRow
                                        label="Reported by"
                                        value={
                                            d.reporter?.name ?? 'Not recorded'
                                        }
                                    />
                                    {d.hs_event ? (
                                        <ReviewRow
                                            label="Safety review owner"
                                            value={
                                                d.hs_event.handover.owner
                                                    ?.name ?? 'Not assigned'
                                            }
                                        />
                                    ) : null}
                                    {d.investigator ? (
                                        <ReviewRow
                                            label="Historical investigator"
                                            value={d.investigator}
                                        />
                                    ) : null}
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        Follow-ups and corrective actions keep
                                        their own assignees.
                                    </p>
                                </CardContent>
                            </Card>
                        </aside>,
                        <div
                            key="details"
                            className={`min-w-0 space-y-5 xl:col-start-1 ${section === 'overview' ? 'xl:row-start-2' : 'xl:row-start-1'}`}
                        >
                            {/* Keep entered follow-up/action text mounted when changing sections. */}
                            <section
                                hidden={section !== 'overview'}
                                aria-label="Incident overview"
                            >
                                <OverviewSection
                                    d={d}
                                    isNearMiss={d.type === 'near_miss'}
                                    part="details"
                                />
                            </section>
                            <section
                                hidden={section !== 'followups'}
                                aria-label="Incident follow-ups"
                            >
                                <FollowupsSection
                                    d={d}
                                    onComplete={completeFollowup}
                                    onDraftStateChange={drafts.onFollowupDraft}
                                />
                            </section>
                            <section
                                hidden={section !== 'evidence'}
                                aria-label="Incident evidence"
                                className="space-y-4"
                            >
                                <PhotosSection d={d} />
                                <RecordingWindowEditor
                                        preview={d.recording_preview}
                                        actorId={props.auth.user.id}
                                        incidentId={d.id}
                                        refreshing={recordingsLoading}
                                        onDraftStateChange={drafts.onRecordingWindowDraft}
                                        requestDiscard={drafts.request}
                                        onSaved={() => onRecordingPageChange(1)}
                                        onRefresh={() => onRecordingPageChange(d.recording_evidence?.references.page ?? 1)}
                                />
                                <RecordingReferenceChooser
                                    actorId={props.auth.user.id}
                                    incidentId={d.id}
                                    preview={d.recording_preview}
                                    evidence={d.recording_evidence}
                                    refreshing={recordingsLoading}
                                    onDraftStateChange={drafts.onRecordingReferenceDraft}
                                    requestDiscard={drafts.request}
                                    onSaved={() => onRecordingPageChange(1)}
                                    onRefresh={() => onRecordingPageChange(d.recording_evidence?.references.page ?? 1)}
                                />
                                {d.recording_evidence ? (
                                    <RecordingReferenceEvidence
                                        incidentId={d.id}
                                        evidence={d.recording_evidence}
                                        onPageChange={onRecordingPageChange}
                                        loading={recordingsLoading}
                                    />
                                ) : (
                                    <p role="status" className="text-subtle text-muted-foreground">
                                        Recording reference information is unavailable. Reload the incident to check current access.
                                    </p>
                                )}
                            </section>
                            <section
                                hidden={section !== 'investigation'}
                                aria-label="Incident investigation"
                            >
                                <InvestigationSection
                                    d={d}
                                    onDraftStateChange={
                                        drafts.onCorrectiveActionDraft
                                    }
                                />
                            </section>
                            <section
                                hidden={section !== 'linked'}
                                aria-label="Linked incident records"
                            >
                                <LinkedSection d={d} clientName={person} />
                            </section>
                            <section
                                hidden={section !== 'history'}
                                aria-label="Incident history"
                            >
                                <TimelineSection d={d} />
                            </section>
                        </div>,
                    ].sort(
                        (left, right) =>
                            bodyOrder.indexOf(String(left.key)) -
                            bodyOrder.indexOf(String(right.key)),
                    )}
                </div>
            </PageLayout>
            <TabSearchPalette
                open={findOpen}
                onClose={() => setFindOpen(false)}
                groups={groups}
                onTab={selectSection}
                testIdPrefix="incident-workspace"
                searchLabel="Find an incident section"
            />
            {action ? (
                <IncidentRecordActionDialog
                    key={action}
                    detail={d}
                    action={action}
                    onClose={() => setAction(null)}
                />
            ) : null}
            {drafts.confirmation}
            {requestResponse ? (
                <IncidentResponseRequestDialog
                    detail={d}
                    onClose={() => setRequestResponse(false)}
                />
            ) : null}
        </AppLayout>
    );
}
