/**
 * Emergency drill record (approved H&S drawings pg-hsn-66, 66b–66i — Stephan
 * 23 Oct 2026): one header for the drill — its outcome, attendance and open
 * findings, then where it is up to (scheduled, run, completed, findings
 * resolved) with the next step — the seven sections on the rail, and each
 * section beside the roll-call checks and the linked records. Commands open in
 * the page and keep their forms; the counts and checks caveats stay word for
 * word.
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
    type PageHeaderNextStep,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
import {
    RowList,
    SideColumn,
    Surface,
    type RowItem,
} from '@/components/signature/blocks';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDate, formatDateOnly, formatTime } from '@/lib/datetime';
import {
    drillHref,
    drillLocation,
    type DrillCommand,
    type DrillSection,
} from '@/lib/health-safety/drill-navigation';
import {
    outcomeMeta,
    statusMeta,
    titleCase,
} from '@/pages/health-safety/drills/shared';
import { router, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    History,
    Link2,
    Paperclip,
    RadioTower,
    ShieldAlert,
    Siren,
    Timer,
    Users,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { DrillCompletionForm } from './drill-completion-form';
import { DrillEvidence } from './drill-evidence';
import {
    DrillChecks,
    DrillOverview,
    DrillRunFacts,
    urgentFindings,
} from './drill-facts';
import {
    DrillConnections,
    DrillFindings,
    DrillHistory,
    DrillParticipants,
} from './drill-panels';
import { DrillPlanningForm } from './drill-planning-form';
import {
    DrillRecordCommandForm,
    type DrillRecordCommand,
} from './drill-record-command';
import { DrillRemedyForm } from './drill-remedy-form';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'run', label: 'Run & timings', icon: Timer },
    { key: 'participants', label: 'Participants', icon: Users },
    { key: 'findings', label: 'Findings', icon: ClipboardList },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;

/** "Thu 8 Oct" */
function shortDay(iso: string | null | undefined): string | null {
    return iso ? formatDate(iso, '') || null : null;
}

export function DrillWorkspace({
    detail: d,
    pickers,
}: {
    detail: DrillWorkspaceDetail;
    pickers: DrillPickers;
}) {
    const { url } = usePage();
    const location = drillLocation(url);
    const [section, setSection] = useState<DrillSection>(location.section);
    const [command, setCommand] = useState<DrillCommand | null>(
        location.command,
    );
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const next = drillLocation(url);
        setSection(next.section);
        setCommand(next.command);
    }, [url]);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
    }, [section, command]);
    const href = (
        next: DrillSection,
        action: DrillCommand | null = null,
        finding: number | null = location.finding,
        participant: number | null = null,
    ) =>
        drillHref(d.id, {
            section: next,
            command: action,
            finding,
            participant,
            returnTo: location.returnTo,
        });
    const move = (
        next: DrillSection,
        action: DrillCommand | null = null,
        finding: number | null = location.finding,
        participant: number | null = null,
    ) =>
        drafts.request(() => {
            interacted.current = true;
            router.replace({
                url: href(next, action, finding, participant),
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSection(next);
                    setCommand(action);
                },
            });
        });
    const saved = () =>
        router.replace({
            url: href(section),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setCommand(null),
        });
    const cancel = () => move(section);
    const overdue =
        d.status === 'scheduled' &&
        !!d.scheduled_at &&
        Date.parse(d.scheduled_at) < Date.now();
    const status = statusMeta(overdue ? 'overdue' : d.status);
    const outcome = outcomeMeta(d.outcome);
    const finding = d.findings.find((item) => item.id === location.finding);
    const shortCommand =
        command &&
        [
            'start',
            'cancel',
            'add-participant',
            'edit-participant',
            'add-finding',
            'edit-finding',
            'resolve-finding',
        ].includes(command);

    /* ----- header actions: one primary, the rest glass ----- */

    const lifecycle: { label: string; run: () => void } | null = d.can.start
        ? { label: 'Start drill', run: () => move('run', 'start') }
        : d.can.complete
          ? { label: 'Record completion', run: () => move('run', 'complete') }
          : null;
    const sectionPrimary: { label: string; run: () => void } | null =
        section === 'participants' && d.can.add_participant
            ? {
                  label: 'Add participant',
                  run: () => move('participants', 'add-participant'),
              }
            : section === 'findings' && d.can.add_finding
              ? {
                    label: 'Record finding',
                    run: () => move('findings', 'add-finding'),
                }
              : null;
    const primary = lifecycle ?? sectionPrimary;
    const glass: ReactNode[] = [];
    if (d.can.edit)
        glass.push(
            <PageHeaderGlassButton
                key="edit"
                onClick={() => move('overview', 'edit')}
            >
                Edit planning
            </PageHeaderGlassButton>,
        );
    if (d.can.cancel)
        glass.push(
            <PageHeaderGlassButton
                key="cancel"
                onClick={() => move('overview', 'cancel')}
            >
                Cancel drill
            </PageHeaderGlassButton>,
        );
    if (lifecycle && sectionPrimary)
        glass.push(
            <PageHeaderGlassButton key="section" onClick={sectionPrimary.run}>
                {sectionPrimary.label}
            </PageHeaderGlassButton>,
        );
    if (section === 'participants' && d.exports.participants_href)
        glass.push(
            <PageHeaderGlassButton key="export-participants" asChild>
                <a href={d.exports.participants_href}>Export participants</a>
            </PageHeaderGlassButton>,
        );
    if (section === 'evidence' && d.exports.evidence_href)
        glass.push(
            <PageHeaderGlassButton key="export-evidence" asChild>
                <a href={d.exports.evidence_href}>Export evidence list</a>
            </PageHeaderGlassButton>,
        );

    /* ----- facts, stages, next step ----- */

    const facts: PageHeaderFact[] = [
        {
            value: outcome?.label ?? 'Not yet',
            label: 'Outcome',
            tone:
                outcome?.tone === 'critical'
                    ? 'critical'
                    : outcome?.tone === 'warning'
                      ? 'warning'
                      : outcome?.tone === 'success'
                        ? 'success'
                        : undefined,
        },
        {
            value: `${d.counts.attended} of ${d.counts.assigned}`,
            label: 'Attendance',
        },
        {
            value: String(d.counts.findings_open),
            label: 'Open findings',
            tone:
                d.counts.findings_overdue > 0
                    ? 'critical'
                    : d.counts.findings_open > 0
                      ? 'warning'
                      : undefined,
        },
    ];
    const open = urgentFindings(d.findings).filter(
        (f) => f.status !== 'resolved' && f.status !== 'closed',
    );
    const urgent = open[0];
    const runMeta =
        d.started_at && d.completed_at
            ? `${formatDate(d.started_at)}, ${formatTime(d.started_at)} – ${formatTime(d.completed_at)}`
            : d.started_at
              ? `Started ${formatDate(d.started_at)}, ${formatTime(d.started_at)}`
              : undefined;
    const scheduledMeta =
        [d.created_by_name, shortDay(d.created_at)]
            .filter(Boolean)
            .join(' · ') || undefined;
    const steps: PageHeaderStage[] =
        d.status === 'cancelled'
            ? [
                  { label: 'Scheduled', meta: scheduledMeta, state: 'done' },
                  {
                      label: 'Cancelled',
                      meta: shortDay(d.cancelled_at) ?? undefined,
                      state: 'done',
                  },
              ]
            : [
                  { label: 'Scheduled', meta: scheduledMeta, state: 'done' },
                  d.status === 'completed'
                      ? { label: 'Run', meta: runMeta, state: 'done' }
                      : d.status === 'in_progress'
                        ? {
                              label: 'Run',
                              meta: runMeta,
                              state: 'now',
                              tone: 'warning',
                          }
                        : {
                              label: 'Run',
                              meta: d.scheduled_at
                                  ? `${overdue ? 'Was planned' : 'Planned'} ${formatDate(d.scheduled_at)}, ${formatTime(d.scheduled_at)}`
                                  : undefined,
                              state: 'now',
                              tone: overdue ? 'critical' : undefined,
                          },
                  {
                      label: 'Completed',
                      meta:
                          d.status === 'completed'
                              ? [outcome?.label, shortDay(d.completed_at)]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                              : undefined,
                      state: d.status === 'completed' ? 'done' : 'todo',
                  },
                  {
                      label: 'Findings resolved',
                      meta:
                          d.status === 'completed'
                              ? open.length
                                  ? `${open.length} open${d.counts.findings_overdue ? ` · ${d.counts.findings_overdue} overdue` : ''}`
                                  : 'None open'
                              : undefined,
                      state:
                          d.status === 'completed'
                              ? open.length
                                  ? 'now'
                                  : 'done'
                              : 'todo',
                      tone:
                          d.status === 'completed' && open.length
                              ? d.counts.findings_overdue
                                  ? 'critical'
                                  : 'warning'
                              : undefined,
                  },
              ];
    const next: PageHeaderNextStep | null =
        d.status === 'in_progress'
            ? {
                  label: 'Next step · drill in progress',
                  text: 'Record what happened',
                  tone: 'warning',
                  meta: `${d.is_unannounced ? 'Unannounced · ' : ''}the outcome, timings, counts and checks`,
              }
            : d.status === 'scheduled'
              ? {
                    label: overdue ? 'Next step · overdue' : 'Next step',
                    text: d.scheduled_at
                        ? overdue
                            ? `Run the drill — it was planned for ${formatDate(d.scheduled_at)}, ${formatTime(d.scheduled_at)}`
                            : `Run the drill on ${formatDate(d.scheduled_at)}, ${formatTime(d.scheduled_at)}`
                        : 'Run the drill',
                    tone: overdue ? 'critical' : undefined,
                    meta: d.coordinator
                        ? `Coordinator ${d.coordinator.name}`
                        : undefined,
                }
              : d.status === 'completed' && urgent
                ? {
                      label: urgent.is_overdue
                          ? `Next step · overdue since ${formatDateOnly(urgent.due_date, 'its due date')}`
                          : urgent.due_date
                            ? `Next step · due ${formatDateOnly(urgent.due_date)}`
                            : 'Next step',
                      text: `${urgent.assignee_name ?? 'Unassigned'} — ${urgent.description}`,
                      tone: urgent.is_overdue ? 'critical' : 'warning',
                      meta: [
                          urgent.severity
                              ? `${titleCase(urgent.severity)} severity`
                              : null,
                          urgent.corrective_action_record
                              ? `${urgent.corrective_action_record.reference_number ?? 'Corrective action'} ${(urgent.corrective_action_record.display_state ?? titleCase(urgent.corrective_action_record.status)).toLowerCase()} · Effectiveness: ${
                                    urgent.corrective_action_record
                                        .effectiveness_confirmed === true
                                        ? 'Confirmed'
                                        : urgent.corrective_action_record
                                                .effectiveness_confirmed ===
                                            false
                                          ? 'Not confirmed'
                                          : 'Not recorded'
                                }`
                              : 'No corrective action linked yet',
                      ]
                          .filter(Boolean)
                          .join(' · '),
                  }
                : null;

    /* ----- side column ----- */

    const linked: RowItem[] = [
        ...d.connections.safety_reviews.map((r) => ({
            key: `review-${r.id}`,
            leading: <ShieldAlert className="size-4" />,
            title: `Safety review ${r.reference_number ?? ''}`.trim(),
            sub: [r.title, r.display_state ?? titleCase(r.status)]
                .filter(Boolean)
                .join(' · '),
            href: r.href,
        })),
        ...d.connections.corrective_actions.map((r) => ({
            key: `action-${r.id}`,
            leading: <Wrench className="size-4" />,
            title: `Corrective action ${r.reference_number ?? ''}`.trim(),
            sub: [r.title, r.display_state ?? titleCase(r.status)]
                .filter(Boolean)
                .join(' · '),
            href: r.href,
        })),
        ...d.connections.control_room.map((r) => ({
            key: `room-${r.id}`,
            leading: <RadioTower className="size-4" />,
            title: r.reference_number
                ? `Control Room ${r.reference_number}`
                : 'Control Room',
            sub: [r.title, r.display_state ?? titleCase(r.status)]
                .filter(Boolean)
                .join(' · '),
            href: r.href,
        })),
        ...d.connections.next_drills.slice(0, 1).map((r) => ({
            key: `next-${r.id}`,
            leading: <Siren className="size-4" />,
            title: `Next drill ${r.reference}`,
            sub: [r.title, shortDay(r.scheduled_at)]
                .filter(Boolean)
                .join(' · '),
            href: r.href,
        })),
    ];
    const formCommand =
        command === 'complete' || command === 'edit' || command === 'schedule-next';
    const side = formCommand ? null : (
        <>
            <DrillChecks detail={d} />
            {section !== 'connections' && linked.length ? (
                <RowList title="Linked records" items={linked} />
            ) : null}
        </>
    );

    /* ----- main ----- */

    let main: ReactNode;
    if (command) {
        main = (
            <Surface
                key={`${command}-${location.finding ?? ''}-${location.participant ?? ''}`}
            >
                <div className="p-[18px]">
                    {shortCommand && (
                        <DrillRecordCommandForm
                            detail={d}
                            pickers={pickers}
                            command={command as DrillRecordCommand}
                            findingId={location.finding}
                            participantId={location.participant}
                            context={{
                                return_to: location.returnTo,
                                section,
                                action: command,
                                ...(location.participant
                                    ? { participant: location.participant }
                                    : {}),
                            }}
                            onSaved={saved}
                            onCancel={cancel}
                        />
                    )}
                    {(command === 'edit' || command === 'schedule-next') && (
                        <DrillPlanningForm
                            detail={d}
                            pickers={pickers}
                            mode={command}
                            returnTo={location.returnTo}
                            onSaved={saved}
                            onCancel={cancel}
                        />
                    )}
                    {command === 'complete' && (
                        <DrillCompletionForm
                            detail={d}
                            pickers={pickers}
                            returnTo={location.returnTo}
                            onSaved={saved}
                            onCancel={cancel}
                        />
                    )}
                    {command === 'corrective-action' &&
                        (finding ? (
                            <DrillRemedyForm
                                detail={d}
                                finding={finding}
                                returnTo={location.returnTo}
                                onSaved={saved}
                                onCancel={cancel}
                            />
                        ) : (
                            <p role="alert">
                                The selected finding is not available in this
                                drill.
                            </p>
                        ))}
                </div>
            </Surface>
        );
    } else if (section === 'overview') {
        main = (
            <DrillOverview detail={d} onFindings={() => move('findings')} />
        );
    } else if (section === 'run') {
        main = <DrillRunFacts detail={d} />;
    } else if (section === 'participants') {
        main = <DrillParticipants detail={d} move={move} />;
    } else if (section === 'findings') {
        main = (
            <DrillFindings
                detail={d}
                selected={location.finding}
                move={move}
            />
        );
    } else if (section === 'evidence') {
        main = <DrillEvidence detail={d} returnTo={location.returnTo} />;
    } else if (section === 'history') {
        main = <DrillHistory detail={d} />;
    } else {
        main = <DrillConnections detail={d} move={move} />;
    }

    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        backHref={location.returnTo}
                        icon={Siren}
                        title={d.title}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    status.tone === 'info'
                                        ? 'neutral'
                                        : status.tone
                                }
                            >
                                {status.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${d.reference} · ${d.site?.name ?? 'Site not recorded'}`}
                        actions={
                            glass.length || primary ? (
                                <>
                                    {glass}
                                    {primary ? (
                                        <PageHeaderPrimaryButton
                                            onClick={primary.run}
                                        >
                                            {primary.label}
                                        </PageHeaderPrimaryButton>
                                    ) : null}
                                </>
                            ) : undefined
                        }
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            <PageHeaderStages
                                steps={steps}
                                next={next}
                                ariaLabel="Drill stage"
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={[...SECTIONS]}
                                value={section}
                                onSelect={(item) => move(item as DrillSection)}
                                ariaLabel="Drill sections"
                            />
                        }
                    />
                }
            >
                <div
                    className={
                        side
                            ? 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]'
                            : 'grid min-w-0 gap-5'
                    }
                >
                    <div
                        ref={panel}
                        tabIndex={-1}
                        aria-label={
                            SECTIONS.find((s) => s.key === section)?.label
                        }
                        className="grid min-w-0 gap-5 outline-none"
                    >
                        {main}
                    </div>
                    {side ? (
                        <aside
                            className="min-w-0"
                            aria-label="Drill checks and linked records"
                        >
                            <SideColumn>{side}</SideColumn>
                        </aside>
                    ) : null}
                </div>
            </PageLayout>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}
