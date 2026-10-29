/**
 * The incident page (approved H&S drawings pg-hsn-2 … 2j, Stephan 23 Oct
 * 2026): one record header with the facts, the six-stage line and who does
 * what next; one row of tabs (Overview, Follow-ups, Safety review, Evidence,
 * History); the owner, the closing checks and the linked records in the side
 * column. Every command keeps its own guarded dialog.
 */
import {
    ACTION_META,
    FollowupsSection,
    incidentWorksafe,
    InvestigationSection,
    LinkedSection,
    linkedRecordItems,
    PhotosSection,
    safeguardingEscalation,
    SafeguardingEscalationText,
    TimelineSection,
    type IncidentDetail,
    type LifecycleAction,
} from '@/components/incidents/incident-detail-dialog';
import {
    IncidentOwnershipPanel,
    incidentAssignLabel,
} from '@/components/incidents/incident-ownership-panel';
import { IncidentRecordActionDialog } from '@/components/incidents/incident-record-action-dialog';
import { IncidentResponseRequestDialog } from '@/components/incidents/incident-response-request-dialog';
import {
    dateTimeWithYear,
    dateWithYear,
    daysSince,
    incidentNextStep,
    incidentStageLine,
    incidentTypeLabel,
    ownerNeeded,
    plural,
    SEVERITY_LABELS,
    SEVERITY_VARIANT,
    shortWhen,
    INCIDENT_STATUS_LABELS,
    worksafePending,
} from '@/components/incidents/incident-stages';
import { LinkedOperationalEvidence } from '@/components/incidents/linked-operational-evidence';
import { RecordingReferenceChooser } from '@/components/incidents/recording-reference-chooser';
import {
    RecordingReferenceEvidence,
    type RecordingReferenceEvidenceData,
} from '@/components/incidents/recording-reference-evidence';
import type { RecordingPreview } from '@/components/incidents/recording-window-command';
import { RecordingWindowEditor } from '@/components/incidents/recording-window-editor';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    TabSearchPalette,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
} from '@/components/page/page-header-strip';
import {
    DutyClock,
    Log,
    Note,
    PeopleCards,
    Ring,
    RingCard,
    RowList,
    SideColumn,
    Story,
    Surface,
    type LogItem,
    type PersonCardItem,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useIncidentWorkspaceDrafts } from '@/hooks/use-incident-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/datetime';
import { incidentRegisterReturn, incidentReturnLabel } from '@/lib/incidents/navigation';
import { JOURNEY_TERM_DEFINITIONS } from '@/lib/journey-labels';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileText,
    HeartPulse,
    LinkIcon,
    ListTodo,
    MapPin,
    Paperclip,
    Pencil,
    RadioTower,
    RotateCw,
    Search,
    ShieldAlert,
} from 'lucide-react';
import { useCallback, useRef, useState, type ReactNode } from 'react';

type Section = 'overview' | 'care' | 'followups' | 'investigation' | 'evidence' | 'history' | 'linked';
type IncidentWorkspaceDetail = IncidentDetail & {
    recording_evidence?: RecordingReferenceEvidenceData;
    recording_preview?: RecordingPreview;
};

const SECTIONS: (PageHeaderRailItem<Section> & { icon: typeof FileText })[] = [
    { key: 'overview', label: 'Overview', icon: FileText },
    // One accident, one place (approved drawing D3): first aid, restraints and other linked health records.
    { key: 'care', label: 'Injury and first aid', icon: HeartPulse },
    { key: 'investigation', label: 'Safety review', icon: Search },
    { key: 'followups', label: 'Follow-ups', icon: ListTodo },
    { key: 'evidence', label: 'Files', icon: Paperclip },
    { key: 'history', label: 'History', icon: Clock },
    // Seventh tab: sits under More (drawing D3 keeps six on the rail).
    { key: 'linked', label: 'Linked records', icon: LinkIcon },
];
/** Older deep links. */
const LEGACY_SECTIONS: Record<string, Section> = {};

const SOURCE_STORY: Record<string, string> = {
    manual: 'Staff report',
    control_room: 'Control room',
    sensor: 'Sensor',
    automated: 'Automated',
};

const BREADCRUMB_ROOT = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Health & safety', href: '/health-safety' },
];

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
                    ...BREADCRUMB_ROOT,
                    { title: 'Incidents', href: '/incidents' },
                    { title: 'Incident unavailable', href: url },
                ]}
            >
                <Head title="Incident unavailable" />
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            backHref="/incidents"
                            title="Incident unavailable"
                            icon={AlertTriangle}
                            wrapTitle
                            subline="You can’t see this incident with your current access"
                            actions={
                                <PageHeaderPrimaryButton
                                    icon={RotateCw}
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
                    <div className="max-w-[54rem]">
                        <Note tone="warning" icon={AlertTriangle}>
                            <p role="alert">
                                Reload the page to check your access before you
                                open or change anything.
                            </p>
                        </Note>
                    </div>
                </PageLayout>
            </AppLayout>
        );
    }
    return (
        <IncidentWorkspace key={`${viewer_id}:${detail.id}`} detail={detail} />
    );
}

/** The record's status guide: what the incident's words mean. */
function StatusGuide() {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <PageHeaderGlassButton>
                    Status guide
                </PageHeaderGlassButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <p className="mb-2 text-sm font-semibold">
                    What these terms mean
                </p>
                <dl className="space-y-2">
                    {(['status', 'severity', 'priority'] as const).map(
                        (term) => (
                            <div key={term}>
                                <dt className="text-xs font-semibold">
                                    {JOURNEY_TERM_DEFINITIONS[term].label}
                                </dt>
                                <dd className="text-caption">
                                    {JOURNEY_TERM_DEFINITIONS[term].definition}
                                </dd>
                            </div>
                        ),
                    )}
                </dl>
            </PopoverContent>
        </Popover>
    );
}

export function IncidentWorkspace({ detail: d }: { detail: IncidentWorkspaceDetail }) {
    const drafts = useIncidentWorkspaceDrafts(d.id);
    const { url, props } = usePage<SharedData>();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const requested = params.get('section') ?? '';
    const returnTo = incidentRegisterReturn(params.get('return_to'));
    const section: Section =
        SECTIONS.find((item) => item.key === requested)?.key ??
        LEGACY_SECTIONS[requested] ??
        'overview';
    const [action, setAction] = useState<LifecycleAction | 'edit' | null>(null);
    const [requestResponse, setRequestResponse] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [findOpen, setFindOpen] = useState(false);
    const [recordingsLoading, setRecordingsLoading] = useState(false);
    const recordingVisitPending = useRef(false);
    const openFind = useCallback(() => {
        if (!action && !requestResponse && !assignOpen) setFindOpen(true);
    }, [action, requestResponse, assignOpen]);
    useGroupedProfileSearchShortcut(openFind);

    const reference = d.ref ?? `INC-${d.id}`;
    const person = d.client
        ? `${d.client.first_name} ${d.client.last_name}`.trim()
        : 'No person linked';
    const typeLabel = incidentTypeLabel(d.type);
    const title = d.client ? `${typeLabel} — ${person}` : typeLabel;
    const ownership = d.ownership;
    const needed =
        !['draft', 'closed'].includes(d.status) && ownerNeeded(ownership);
    const canAssign = Boolean(ownership?.can_assign);
    const overdueFollowups = d.followups.filter(
        (item) => !item.completed_at && item.overdue,
    ).length;
    const openFollowups = d.followups.filter((item) => !item.completed_at).length;
    const worksafe = incidentWorksafe(d);
    const readOnly = !(
        d.can.update ||
        d.can.submit ||
        d.can.review ||
        d.can.close ||
        d.can.reopen ||
        d.can.followupsManage ||
        d.can.followupsComplete ||
        d.can.raiseCorrectiveAction ||
        d.can.portalManage ||
        d.can.requestResponse ||
        canAssign
    );

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
    const primary = responseHref
        ? 'response'
        : needed && canAssign
          ? 'assign'
          : recordAction
            ? 'record'
            : canEditDraft
              ? 'edit'
              : d.can.requestResponse
                ? 'request'
                : null;

    const line = incidentStageLine(d);
    const next = incidentNextStep(d, line, {
        readOnly,
        responseActive,
        responseHref,
    });

    const onSection = (nextSection: Section) =>
        router.get(
            `/incidents/${d.id}`,
            {
                section: nextSection,
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

    // Linked health records: readable first aid and restraints, and the ones this viewer may not open (D3 privacy).
    const careItems = linkedRecordItems(d, person).filter((item) =>
        /^(fa|re|hidden)-/.test(String(item.key)),
    );
    const railItems: PageHeaderRailItem<Section>[] = SECTIONS.map((item) => ({
        key: item.key,
        label: item.label,
        ...(item.key === 'followups' && openFollowups
            ? { count: openFollowups }
            : {}),
        ...(item.key === 'care' && careItems.length
            ? { count: careItems.length }
            : {}),
        ...(item.key === 'evidence' && d.attachments.length
            ? { count: d.attachments.length }
            : {}),
    }));
    const findGroups: GroupedProfileNavGroup[] = [
        { key: 'incident', label: 'Incident', icon: FileText, tabs: SECTIONS },
    ];
    const selectSection = (key: string) => {
        const target = SECTIONS.find((item) => item.key === key);
        if (target) onSection(target.key);
    };

    const facts: PageHeaderFact[] = [
        d.type === 'near_miss' && d.potential_severity
            ? {
                  value: SEVERITY_LABELS[d.potential_severity] ?? d.potential_severity,
                  label: 'Potential severity',
                  tone: SEVERITY_VARIANT[d.potential_severity],
              }
            : {
                  // One chip, e.g. "Medium harm" (approved drawing D3 wording).
                  value: (SEVERITY_LABELS[d.severity] ?? d.severity) + ' harm',
                  tone: SEVERITY_VARIANT[d.severity],
              },
        needed
            ? {
                  value: needed === 'unassigned' ? 'Needs an owner' : 'Owner needs replacing',
                  label: 'Owner',
                  tone: 'warning' as const,
              }
            : d.status === 'closed'
              ? { value: dateWithYear(d.closed_at, ''), label: 'Closed' }
              : (() => {
                    const days = daysSince(d.occurred_at);
                    return days === null
                        ? { value: '' }
                        : { value: plural(days, 'day'), label: 'Open for' };
                })(),
        ...(worksafePending(worksafe.notifiable, worksafe.status)
            ? [{ value: 'To notify', label: 'WorkSafe', tone: 'warning' as const }]
            : []),
        ...(overdueFollowups
            ? [
                  {
                      value: overdueFollowups,
                      label: overdueFollowups === 1 ? 'Overdue follow-up' : 'Overdue follow-ups',
                      tone: 'critical' as const,
                  },
              ]
            : []),
    ];

    const recordButton = (glass: boolean) => {
        if (!recordAction) return null;
        const Comp = glass ? PageHeaderGlassButton : PageHeaderPrimaryButton;
        return (
            <Comp
                onClick={() => setAction(recordAction)}
                disabled={recordAction === 'close' && !d.close_gate.allowed}
            >
                {ACTION_META[recordAction].title}
            </Comp>
        );
    };
    const actions = (
        <>
            {canAssign && primary !== 'assign' ? (
                <PageHeaderGlassButton onClick={() => setAssignOpen(true)}>
                    {incidentAssignLabel(ownership!)}
                </PageHeaderGlassButton>
            ) : null}
            {recordAction && primary !== 'record' ? recordButton(true) : null}
            {canEditDraft && primary !== 'edit' ? (
                <PageHeaderGlassButton icon={Pencil} onClick={() => setAction('edit')}>
                    Edit draft
                </PageHeaderGlassButton>
            ) : null}
            {d.can.requestResponse && primary !== 'request' ? (
                <PageHeaderGlassButton icon={RadioTower} onClick={() => setRequestResponse(true)}>
                    Request Control Room response
                </PageHeaderGlassButton>
            ) : null}
            <StatusGuide />
            {primary === 'response' && responseHref ? (
                <PageHeaderPrimaryButton asChild icon={RadioTower}>
                    <Link href={responseHref}>Open response</Link>
                </PageHeaderPrimaryButton>
            ) : primary === 'assign' ? (
                <PageHeaderPrimaryButton onClick={() => setAssignOpen(true)}>
                    {incidentAssignLabel(ownership!)}
                </PageHeaderPrimaryButton>
            ) : primary === 'record' ? (
                recordButton(false)
            ) : primary === 'edit' ? (
                <PageHeaderPrimaryButton icon={Pencil} onClick={() => setAction('edit')}>
                    Edit draft
                </PageHeaderPrimaryButton>
            ) : primary === 'request' ? (
                <PageHeaderPrimaryButton icon={RadioTower} onClick={() => setRequestResponse(true)}>
                    Request Control Room response
                </PageHeaderPrimaryButton>
            ) : null}
        </>
    );

    const occurred = d.occurred_at ? dateTimeWithYear(d.occurred_at) : 'Occurrence time not recorded';
    const subline = [
        reference,
        d.client ? (d.client.site ?? person) : 'No person linked',
        occurred,
        ownership?.state === 'assigned' && ownership.owner ? `Owner ${ownership.owner.name}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const linkedItems = linkedRecordItems(d, person);
    const linkedList = linkedItems.length ? (
        <RowList title={readOnly ? 'Other linked records' : 'Linked records'} items={linkedItems} />
    ) : null;
    const showGate = !readOnly && d.status !== 'closed' && d.close_gate.requirements.length > 0;

    return (
        <AppLayout
            breadcrumbs={[
                ...BREADCRUMB_ROOT,
                { title: incidentReturnLabel(returnTo), href: returnTo },
                { title, href: `/incidents/${d.id}` },
            ]}
        >
            <Head title={`${title} · ${reference}`} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        backHref={returnTo}
                        icon={AlertTriangle}
                        title={title}
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
                                {INCIDENT_STATUS_LABELS[d.status] ?? incidentTypeLabel(d.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={subline}
                        actions={actions}
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            <PageHeaderStages
                                steps={line.steps}
                                next={next}
                                ariaLabel="Where the incident is up to"
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={section}
                                onSelect={onSection}
                                onFind={openFind}
                                maxVisible={6}
                                ariaLabel="Incident sections"
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div
                        id="incident-workspace-panel"
                        role="tabpanel"
                        aria-label={SECTIONS.find((item) => item.key === section)?.label}
                        className="grid min-w-0 gap-5"
                    >
                        {/* Keep entered follow-up/action text mounted when changing sections. */}
                        <section hidden={section !== 'overview'} aria-label="Incident summary" className="grid min-w-0 gap-5">
                            <OverviewMain
                                d={d}
                                person={person}
                                readOnly={readOnly}
                                linkedList={readOnly ? linkedList : null}
                                onHistory={() => onSection('history')}
                            />
                        </section>
                        <section hidden={section !== 'care'} aria-label="Injury and first aid" className="grid min-w-0 gap-5">
                            <RowList
                                title="Injury and first aid"
                                sub="First aid, restraints and other health records linked to this incident. Each keeps its own record and who can see it."
                                items={careItems}
                                empty="No first aid, restraint or other health record is linked to this incident."
                            />
                        </section>
                        <section hidden={section !== 'followups'} aria-label="Incident follow-ups">
                            <FollowupsSection
                                d={d}
                                onComplete={completeFollowup}
                                onDraftStateChange={drafts.onFollowupDraft}
                            />
                        </section>
                        <section hidden={section !== 'investigation'} aria-label="Incident safety review" className="grid min-w-0 gap-5">
                            <InvestigationSection
                                d={d}
                                onDraftStateChange={drafts.onCorrectiveActionDraft}
                            />
                            {d.linked_operational_evidence?.access !== 'restricted' ? (
                                <LinkedOperationalEvidence
                                    evidence={d.linked_operational_evidence}
                                    showSource={false}
                                />
                            ) : null}
                        </section>
                        <section hidden={section !== 'evidence'} aria-label="Incident evidence" className="grid min-w-0 gap-5">
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
                                <p role="status" className="text-subtle">
                                    Recording reference information is unavailable. Reload the incident to check current access.
                                </p>
                            )}
                        </section>
                        <section hidden={section !== 'history'} aria-label="Incident history">
                            <TimelineSection d={d} />
                        </section>
                        <section hidden={section !== 'linked'} aria-label="Linked records">
                            <LinkedSection d={d} clientName={person} />
                        </section>
                    </div>
                    <aside aria-label="Incident owner, closing checks and linked records">
                        <SideColumn>
                            {ownership ? (
                                <IncidentOwnershipPanel
                                    incidentId={d.id}
                                    reference={reference}
                                    ownership={ownership}
                                    assignOpen={assignOpen}
                                    onAssignOpenChange={setAssignOpen}
                                    showAssign={false}
                                />
                            ) : null}
                            {showGate ? <ClosingChecks d={d} /> : null}
                            {readOnly ? null : linkedList}
                        </SideColumn>
                    </aside>
                </div>
            </PageLayout>
            <TabSearchPalette
                open={findOpen}
                onClose={() => setFindOpen(false)}
                groups={findGroups}
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

/* ------------------------------------------------------------------ */
/*  Overview                                                           */
/* ------------------------------------------------------------------ */

function OverviewMain({
    d,
    person,
    readOnly,
    linkedList,
    onHistory,
}: {
    d: IncidentWorkspaceDetail;
    person: string;
    readOnly: boolean;
    linkedList: ReactNode;
    onHistory: () => void;
}) {
    const worksafe = incidentWorksafe(d);
    const evidence = d.linked_operational_evidence;
    const crRestricted =
        evidence?.access === 'restricted' ||
        (!evidence?.source && d.linked_access?.control_room === 'restricted');
    const isNearMiss = d.type === 'near_miss';
    const story = (
        <Story
            title="What happened"
            sub={
                d.reporter?.name
                    ? `From ${d.reporter.name}’s report${d.submitted_at ? ` · ${dateTimeWithYear(d.submitted_at)}` : ''}`
                    : undefined
            }
            facts={[
                ...(d.client?.site ? [{ icon: MapPin, text: d.client.site }] : []),
                ...(d.occurred_at
                    ? [
                          {
                              icon: Clock,
                              text: (
                                  <>
                                      <b>{formatDate(d.occurred_at)}</b>, {formatTime(d.occurred_at)}
                                  </>
                              ),
                          },
                      ]
                    : []),
                { icon: FileText, text: SOURCE_STORY[d.source] ?? incidentTypeLabel(d.source) },
                ...(isNearMiss && d.potential_severity
                    ? [{ icon: ShieldAlert, text: `Could have been ${(SEVERITY_LABELS[d.potential_severity] ?? d.potential_severity).toLowerCase()}` }]
                    : []),
            ]}
            paras={[
                { h: 'What happened', t: d.description || 'Not recorded' },
                { h: 'What staff did straight away', t: d.immediate_action_taken || 'Not recorded' },
                ...(d.witnesses ? [{ h: 'Witnesses', t: d.witnesses }] : []),
                ...(isNearMiss && d.potential_consequence ? [{ h: 'Could have caused', t: d.potential_consequence }] : []),
            ]}
        />
    );
    const timeline = minuteByMinute(d);
    const people = peopleInvolved(d, person);
    return (
        <>
            {safeguardingEscalation(d) ? (
                <Note tone="warning" icon={ShieldAlert}>
                    <SafeguardingEscalationText d={d} />
                </Note>
            ) : null}
            {worksafe.notifiable ? <WorksafeClock d={d} /> : null}
            {story}
            {!readOnly && timeline.items.length > 2 ? (
                <Log
                    title="Minute by minute"
                    sub={timeline.sub}
                    action={{ label: 'Full history', onClick: onHistory }}
                    items={timeline.items}
                    limit={timeline.items.length}
                />
            ) : null}
            {crRestricted ? (
                <Surface title="From the Control room" aria-label="Linked Control Room evidence">
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>
                            This incident has a linked Control Room record. Your access does not include its notes or files.
                        </span>
                    </p>
                </Surface>
            ) : null}
            {!readOnly && people.length > 1 ? <PeopleCards title="People involved" cols={2} items={people} /> : null}
            {linkedList}
        </>
    );
}

function WorksafeClock({ d }: { d: IncidentWorkspaceDetail }) {
    const worksafe = incidentWorksafe(d);
    const known = d.submitted_at ?? d.occurred_at;
    const acknowledged = worksafe.status === 'acknowledged';
    const days = worksafe.notified ? daysSince(worksafe.notifiedAt) : daysSince(known);
    return (
        <DutyClock
            tone={acknowledged ? 'success' : worksafe.notified ? 'warning' : 'critical'}
            big={acknowledged ? <CheckCircle2 aria-label="Acknowledged" className="size-5" /> : (days ?? '—')}
            unit={acknowledged ? undefined : days === 1 ? 'day' : 'days'}
            kicker="WorkSafe NZ notifiable event"
            title={acknowledged ? 'Acknowledged by WorkSafe' : worksafe.notified ? 'Notified — awaiting acknowledgement' : 'Not notified yet'}
            sub={worksafe.text}
            lines={
                known && !worksafe.notified
                    ? [
                          {
                              state: 'critical',
                              text: (
                                  <>
                                      {d.submitted_at ? 'Reported' : 'Happened'} <b>{dateTimeWithYear(known)}</b>
                                  </>
                              ),
                          },
                      ]
                    : []
            }
            actions={
                d.hs_event?.url ? (
                    <Button asChild size="sm">
                        <Link href={d.hs_event.url}>Open safety review</Link>
                    </Button>
                ) : undefined
            }
        />
    );
}

/** What the incident page knows, in time order: the report and the Control Room notes together. */
function minuteByMinute(d: IncidentWorkspaceDetail): { items: LogItem[]; sub: string } {
    const evidence = d.linked_operational_evidence;
    const readable = evidence && evidence.access !== 'restricted' && evidence.source ? evidence : null;
    const ref = readable?.source?.reference ?? null;
    const events: (LogItem & { at: string })[] = [];
    const add = (at: string | null | undefined, item: LogItem) => {
        if (at) events.push({ ...item, at, time: shortWhen(at) });
    };
    add(d.occurred_at, { title: 'Incident happened', tone: 'critical', sub: d.client?.site ?? undefined });
    if (d.control_room_alert) {
        add(d.control_room_alert.triggered_at, {
            title: 'Control room alert raised',
            tone: 'critical',
            sub: [ref, incidentTypeLabel(d.control_room_alert.alert_type), SEVERITY_LABELS[d.control_room_alert.severity] ?? d.control_room_alert.severity]
                .filter(Boolean)
                .join(' · '),
        });
    }
    for (const note of readable?.notes ?? []) {
        add(note.created_at, {
            title: note.purpose_label,
            tone: 'warning',
            sub: `${note.author?.name ?? 'Unknown operator'} · ${note.content}`,
        });
    }
    if (d.control_room_alert) {
        add(d.control_room_alert.resolved_at, { title: 'Control room alert resolved', tone: 'success', sub: ref ?? undefined });
    }
    add(d.submitted_at, { title: 'Reported', tone: 'info', sub: d.reporter?.name });
    const firstOwner = [...(d.ownership?.history ?? [])].reverse()[0];
    if (firstOwner) {
        add(firstOwner.changed_at, { title: 'Given an owner', tone: 'info', sub: `${firstOwner.owner} · by ${firstOwner.changed_by}` });
    }
    events.sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime());
    return {
        items: events,
        sub: ref ? `From the report and Control room alert ${ref}` : 'From the report',
    };
}

function peopleInvolved(d: IncidentWorkspaceDetail, person: string): PersonCardItem[] {
    const people: PersonCardItem[] = [];
    const seen = new Set<string>();
    const add = (item: PersonCardItem) => {
        const key = item.name.trim().toLowerCase();
        if (!key || seen.has(key)) return;
        seen.add(key);
        people.push(item);
    };
    if (d.client) {
        add({
            name: person,
            sub: `Client${d.client.site ? ` · ${d.client.site}` : ''}`,
            href: `/operations/clients/${d.client.id}/care`,
        });
    }
    if (d.reporter?.name) add({ name: d.reporter.name, sub: 'Reported it' });
    for (const witness of (d.witnesses ?? '').split(/[,;\n]/)) {
        if (witness.trim()) add({ name: witness.trim(), sub: 'Witness' });
    }
    const evidence = d.linked_operational_evidence;
    if (evidence && evidence.access !== 'restricted') {
        for (const note of evidence.notes) {
            if (note.author?.name) {
                add({
                    name: note.author.name,
                    sub: `Control room${evidence.source?.reference ? ` · ${evidence.source.reference}` : ''}`,
                });
            }
        }
    }
    return people;
}

/* ------------------------------------------------------------------ */
/*  Side column                                                        */
/* ------------------------------------------------------------------ */

const GATE_LABELS: Record<string, string> = {
    incident_review: 'Finish the manager review',
    incident_followups: 'Finish all follow-ups',
    incident_investigation: 'Finish the investigation',
};

/** The server's closing checks, read by their rings; unmet ones link to where they're finished. */
function ClosingChecks({ d }: { d: IncidentWorkspaceDetail }) {
    const done = d.close_gate.requirements.filter((item) => item.complete).length;
    return (
        <section aria-label="Transition requirements">
            <RingCard
                value={done}
                of={d.close_gate.requirements.length}
                label={d.close_gate.allowed ? 'Ready to close' : 'Not closed yet'}
                sub={d.close_gate.allowed ? 'Everything needed to close is done' : 'Finish these before you close it'}
                checks={d.close_gate.requirements.map((item) => {
                    const label = item.complete
                        ? item.label
                        : item.key === 'health_safety_governance' && item.href && d.hs_event
                          ? `Close safety review ${d.hs_event.reference_number}`
                          : (GATE_LABELS[item.key] ?? item.label);
                    return {
                        state: item.complete ? ('success' as const) : ('warning' as const),
                        label:
                            !item.complete && item.href ? (
                                <Link
                                    href={item.href}
                                    aria-label={label}
                                    className="rounded-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {label}
                                </Link>
                            ) : (
                                label
                            ),
                    };
                })}
            />
        </section>
    );
}
