/**
 * The incident page (approved H&S drawings pg-hsn-2 … 2j, Stephan 23 Oct
 * 2026; board M14, Stephan 1 Nov 2026): one record header with the facts and
 * the round status steps (Reported › Assigned › Manager review › Closed),
 * which pick the step shown in the Progress card under the header — its fixed
 * next-step bar holds the page's only filled button. One row of tabs; the
 * owner, closure readiness (one line) and the linked safety review in the
 * side column. Every command keeps its own guarded dialog.
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
    type LifecycleAction,
} from '@/components/incidents/incident-detail-dialog';
import {
    IncidentOwnershipPanel,
    incidentAssignLabel,
} from '@/components/incidents/incident-ownership-panel';
import { IncidentNotePopup } from '@/components/incidents/incident-note-popup';
import {
    currentIncidentStep,
    IncidentClosureReadiness,
    incidentNextBar,
    incidentRoundSteps,
    incidentStepPane,
    LinkedSafetyReviewCard,
    type IncidentNoteField,
    type IncidentProgressDetail,
    type IncidentStepKey,
} from '@/components/incidents/incident-progress';
import { IncidentRecordActionDialog } from '@/components/incidents/incident-record-action-dialog';
import { IncidentResponseRequestDialog } from '@/components/incidents/incident-response-request-dialog';
import {
    dateTimeWithYear,
    dateWithYear,
    daysSince,
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
    PageHeaderSteps,
    type PageHeaderFact,
} from '@/components/page/page-header-strip';
import {
    DutyClock,
    Log,
    Note,
    PeopleCards,
    Ring,
    RowList,
    SideColumn,
    Surface,
    type LogItem,
    type PersonCardItem,
} from '@/components/signature/blocks';
import { RecordProgress } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useIncidentWorkspaceDrafts } from '@/hooks/use-incident-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
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
    Paperclip,
    FileDown,
    Pencil,
    RadioTower,
    RotateCw,
    Search,
    ShieldAlert,
} from 'lucide-react';
import { useCallback, useRef, useState, type ReactNode } from 'react';

type Section = 'overview' | 'care' | 'followups' | 'investigation' | 'evidence' | 'history' | 'linked';
type IncidentWorkspaceDetail = IncidentProgressDetail & {
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

const BREADCRUMB_ROOT = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Health and safety', href: '/health-safety' },
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
    const [noteField, setNoteField] = useState<IncidentNoteField | null>(null);
    // The picked step (null: the step the incident is on).
    const [picked, setPicked] = useState<IncidentStepKey | null>(null);
    const [recordingsLoading, setRecordingsLoading] = useState(false);
    const recordingVisitPending = useRef(false);
    const openFind = useCallback(() => {
        if (!action && !requestResponse && !assignOpen && !noteField) setFindOpen(true);
    }, [action, requestResponse, assignOpen, noteField]);
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

    // The Progress card's fixed next-step bar holds the page's only filled button; the header keeps the rest as
    // glass buttons, never offering the bar's action a second time.
    const nextBar = incidentNextBar(d, {
        onRecord: setAction,
        onAssign: () => setAssignOpen(true),
        responseActive,
        responseHref,
    });
    const currentStep = currentIncidentStep(d);
    const selectedStep = picked ?? currentStep;
    const pickStep = (key: string) => {
        const step = (['reported', 'assigned', 'review', 'closed'] as const).find((item) => item === key);
        if (step) setPicked(step === currentStep ? null : step);
    };

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
            : ownership?.state === 'assigned' && ownership.owner
              ? { value: ownership.owner.name, label: 'Incident owner' }
              : { value: '' },
        d.status === 'closed'
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

    // A record action the bar isn't offering stays reachable as a glass header button, and only when it can be done
    // now (no disabled buttons: the bar says what is left).
    const headerRecordAction =
        recordAction &&
        nextBar.owns !== recordAction &&
        !(recordAction === 'close' && !d.close_gate.allowed)
            ? recordAction
            : null;
    const actions = (
        <>
            {canAssign && nextBar.owns !== 'assign' ? (
                <PageHeaderGlassButton onClick={() => setAssignOpen(true)}>
                    {incidentAssignLabel(ownership!)}
                </PageHeaderGlassButton>
            ) : null}
            {headerRecordAction ? (
                <PageHeaderGlassButton onClick={() => setAction(headerRecordAction)}>
                    {ACTION_META[headerRecordAction].title}
                </PageHeaderGlassButton>
            ) : null}
            {canEditDraft ? (
                <PageHeaderGlassButton icon={Pencil} onClick={() => setAction('edit')}>
                    Edit draft
                </PageHeaderGlassButton>
            ) : null}
            {d.can.download_pdf ? (
                // Board M5c note: the closed incident's final record, for whole-record viewers (answer 21).
                <PageHeaderGlassButton icon={FileDown} onClick={() => window.location.assign(`/incidents/${d.id}/pdf`)}>
                    Download PDF
                </PageHeaderGlassButton>
            ) : null}
            {d.can.requestResponse ? (
                <PageHeaderGlassButton icon={RadioTower} onClick={() => setRequestResponse(true)}>
                    Request Control Room response
                </PageHeaderGlassButton>
            ) : null}
            <StatusGuide />
        </>
    );

    const occurred = d.occurred_at ? dateTimeWithYear(d.occurred_at) : 'Occurrence time not recorded';
    const subline = [
        `Incident ${reference}`,
        d.client ? (d.client.site ?? person) : 'No person linked',
        occurred,
    ]
        .filter(Boolean)
        .join(' · ');

    const linkedItems = linkedRecordItems(d, person);
    const linkedList = linkedItems.length ? <RowList title="Associated records" items={linkedItems} /> : null;
    const showGate = !readOnly && d.status !== 'closed' && d.close_gate.requirements.length > 0;
    const stepPane = incidentStepPane(d, selectedStep, {
        onBack: selectedStep !== currentStep ? () => setPicked(null) : undefined,
        onHistory: () => onSection('history'),
        onSection,
        onAssign: () => setAssignOpen(true),
        onAddNote: setNoteField,
    });

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
                            <PageHeaderSteps
                                steps={incidentRoundSteps(d)}
                                selected={selectedStep}
                                onSelect={pickStep}
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
                <div className="grid min-w-0 gap-5">
                    <RecordProgress next={nextBar} pane={stepPane} />
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                        <div
                            id="incident-workspace-panel"
                            role="tabpanel"
                            aria-label={SECTIONS.find((item) => item.key === section)?.label}
                            className="grid min-w-0 gap-5"
                        >
                            {/* Keep entered follow-up/action text mounted when changing sections. */}
                            <section hidden={section !== 'overview'} aria-label="Incident overview" className="grid min-w-0 gap-5">
                                <OverviewMain
                                    d={d}
                                    person={person}
                                    readOnly={readOnly}
                                    linkedList={linkedList}
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
                        <aside aria-label="Incident owner, closure readiness and the linked safety review">
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
                                {showGate ? <IncidentClosureReadiness d={d} /> : null}
                                <LinkedSafetyReviewCard d={d} />
                            </SideColumn>
                        </aside>
                    </div>
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
            {noteField && d.can.add_note ? (
                <IncidentNotePopup
                    key={noteField}
                    incidentId={d.id}
                    reference={reference}
                    reporter={d.reporter?.name ?? null}
                    field={noteField}
                    words={noteField === 'what_happened' ? d.description : d.immediate_action_taken}
                    onClose={() => setNoteField(null)}
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
    // Board M14: no "Incident summary" card here — the report's words are the Progress card's "Reported" step.
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
                    <Button asChild size="sm" variant="outline">
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
