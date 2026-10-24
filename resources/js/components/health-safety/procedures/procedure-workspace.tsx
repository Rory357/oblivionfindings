/**
 * Safe work procedure record (approved H&S drawings pg-hsn-64, 64b–64i —
 * Stephan 23 Oct 2026): one header for the version you are reading — its facts
 * and where it is up to (draft, under review, approved & published, staff
 * sign-off) with the next step — then the seven sections beside the people,
 * your sign-off and the other next steps. Commands open in the page (64h,
 * 64i) and keep their payloads; version, legacy and archive notices stay word
 * for word.
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
    JourneyLine,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    RingCard,
    RowList,
    SideColumn,
    Story,
    Surface,
    type CheckItem,
    type JourneyStep,
    type LogItem,
    type PersonCardItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    formatDateOnly,
    formatDateTimeLong,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    PROCEDURE_COMMANDS,
    procedureHref,
    procedureLocation,
    withProcedureRequest,
    type ProcedureCommand as Command,
    type ProcedureSection,
} from '@/lib/health-safety/procedure-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import {
    FileText,
    HardHat,
    History,
    Info,
    Link2,
    ListChecks,
    MapPin,
    Paperclip,
    ShieldAlert,
    TriangleAlert,
    User,
    Users,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ProcedureWizardDialog } from '../procedure-wizard-dialog';
import {
    PROCEDURE_COMMAND_LABELS,
    ProcedureCommand,
    procedureCommandAllowed,
} from './procedure-command';
import { ProcedureDocuments } from './procedure-documents';
import { procedureCategory, procedureStatus } from './procedure-kit';
import { ProcedureRequestActions } from './procedure-request-actions';
import type {
    ProcedurePickers,
    ProcedureRequest,
    ProcedureWorkspaceDetail,
} from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'steps', label: 'Steps', icon: ListChecks },
    { key: 'ppe', label: 'Hazards & PPE', icon: HardHat },
    { key: 'applies', label: 'Applies to', icon: Users },
    { key: 'documents', label: 'Documents', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;

/** The one header button for the version being read; the rest go to Next steps. */
const PRIMARY_ORDER: Command[] = [
    'acknowledge',
    'approve',
    'submit-review',
    'restore',
];

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function day(iso: string | null | undefined): string | null {
    return iso ? formatDateOnly(toDateInput(iso), '') || null : null;
}

function daysUntil(date: string | null): number | null {
    if (!date || !formatDateOnly(date, '')) return null;
    const today = toDateInput(new Date());
    return Math.round(
        (Date.parse(`${date}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) /
            86400000,
    );
}

function reviewFact(date: string | null): PageHeaderFact {
    const days = daysUntil(date);
    if (days === null) return { value: 'Not scheduled', label: 'Next review' };
    if (days < 0)
        return {
            value: days === -1 ? '1 day overdue' : `${-days} days overdue`,
            label: 'Next review',
            tone: 'critical',
        };
    if (days === 0)
        return { value: 'Today', label: 'Next review', tone: 'warning' };
    if (days <= 30)
        return {
            value: days === 1 ? 'Tomorrow' : `In ${days} days`,
            label: 'Next review',
            tone: 'warning',
        };
    return { value: formatDateOnly(date), label: 'Next review' };
}

const HISTORY_TONES: Record<string, SgTone> = {
    'Version approved': 'success',
    'Version read and acknowledged': 'success',
    'Submitted for review': 'info',
    'Working revision saved': 'info',
    'Changes requested': 'warning',
    'Review recorded': 'info',
    'Procedure archived': 'none',
    'Restored for review': 'info',
    'Document added': 'info',
    'Document removed': 'warning',
    'Source review requested': 'warning',
    'Source review started': 'info',
    'Source review outcome recorded': 'success',
};

/* ------------------------------------------------------------------ */
/*  Workspace                                                          */
/* ------------------------------------------------------------------ */

export function ProcedureWorkspace({
    detail: d,
    pickers,
}: {
    detail: ProcedureWorkspaceDetail;
    pickers: ProcedurePickers;
}) {
    const { url } = usePage();
    const location = procedureLocation(url);
    const [section, setSection] = useState<ProcedureSection>(location.section);
    const [command, setCommand] = useState<Command | null>(location.command);
    const [editing, setEditing] = useState(false);
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const next = procedureLocation(url);
        setSection(next.section);
        setCommand(next.command);
    }, [url]);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
    }, [section, command]);
    const href = (next: ProcedureSection, action: Command | null = null) =>
        procedureHref(d.id, {
            section: next,
            command: action,
            revision: location.revision,
            version: location.version,
            returnTo: location.returnTo,
            request: location.request,
        });
    const move = (next: ProcedureSection, action: Command | null = null) =>
        drafts.request(() => {
            interacted.current = true;
            router.replace({
                url: href(next, action),
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

    const commands = PROCEDURE_COMMANDS.filter((item) =>
        procedureCommandAllowed(d, item),
    );
    const primary = PRIMARY_ORDER.find((item) => commands.includes(item)) ?? null;
    const others = commands.filter((item) => item !== primary);
    const live = d.viewed_revision.kind === 'published';
    const historical = d.viewed_revision.kind === 'historical';
    const working = d.viewed_revision.kind === 'working';
    const version = d.viewed_revision.version;
    const legacy = d.viewed_revision.legacy;

    /* ----- header ----- */

    const workingStatus = d.working_revision?.status ?? d.status;
    const chip =
        d.status === 'archived'
            ? { label: 'Archived', variant: 'neutral' as const }
            : historical
              ? { label: 'Historical version', variant: 'neutral' as const }
              : working
                ? {
                      label: procedureStatus(workingStatus).label,
                      variant:
                          workingStatus === 'under_review'
                              ? ('warning' as const)
                              : ('neutral' as const),
                  }
                : legacy
                  ? { label: 'Approved (older record)', variant: 'neutral' as const }
                  : { label: 'Published', variant: 'success' as const };
    const facts: PageHeaderFact[] = [
        {
            value: String(version),
            label: 'Version',
            tone: working ? 'warning' : undefined,
        },
        reviewFact(d.review_date),
    ];
    const viewedVersion = d.versions.find((v) => v.version === version);
    const submitted = d.history.find(
        (entry) =>
            entry.title === 'Submitted for review' &&
            (!d.publication?.approved_at ||
                !entry.occurred_at ||
                entry.occurred_at <= d.publication.approved_at),
    );
    const approvedMeta =
        [
            d.publication?.approved_by?.name,
            day(d.publication?.approved_at),
        ]
            .filter(Boolean)
            .join(' · ') || undefined;
    const steps: PageHeaderStage[] = working
        ? [
              {
                  label: 'Draft',
                  meta: d.owner?.name,
                  state: workingStatus === 'draft' ? 'now' : 'done',
              },
              {
                  label: 'Under review',
                  meta:
                      workingStatus === 'under_review'
                          ? 'Waiting for approval'
                          : undefined,
                  state: workingStatus === 'under_review' ? 'now' : 'todo',
                  tone:
                      workingStatus === 'under_review' ? 'warning' : undefined,
              },
              {
                  label: 'Approved & published',
                  meta: d.publication
                      ? `Replaces version ${d.publication.version}`
                      : undefined,
                  state: 'todo',
              },
              {
                  label: 'Staff sign-off',
                  meta: 'Everyone confirms again',
                  state: 'todo',
              },
          ]
        : [
              {
                  label: 'Draft',
                  meta:
                      [
                          viewedVersion?.changed_by?.name,
                          day(viewedVersion?.created_at),
                      ]
                          .filter(Boolean)
                          .join(' · ') || undefined,
                  state: 'done',
              },
              {
                  label: 'Under review',
                  meta:
                      live && submitted?.occurred_at
                          ? `Submitted ${day(submitted.occurred_at)}`
                          : undefined,
                  state: 'done',
              },
              {
                  label: 'Approved & published',
                  meta: live ? approvedMeta : undefined,
                  state: 'done',
              },
              d.status === 'archived'
                  ? { label: 'Archived', state: 'done' }
                  : historical
                    ? { label: 'Replaced', meta: 'A later version is in use', state: 'done' }
                    : {
                          label: 'Staff sign-off',
                          meta: 'Everyone confirms this version',
                          state: 'now',
                      },
          ];
    const ack = d.acknowledgement;
    const next: PageHeaderNextStep | null = historical
        ? {
              label:
                  d.status === 'archived' ? 'Archived' : 'Historical version',
              text:
                  d.status === 'archived'
                      ? 'This procedure is archived. These historical instructions are retained for reference and are not current.'
                      : 'You are reading a historical version. Check the current instructions before using this procedure.',
              tone: 'warning',
          }
        : working
          ? {
                label: `Next step · version ${version} is ${workingStatus === 'under_review' ? 'under review' : 'a draft'}`,
                text: procedureCommandAllowed(d, 'approve')
                    ? `Approve and publish version ${version}`
                    : procedureCommandAllowed(d, 'submit-review')
                      ? 'Submit this draft for review'
                      : workingStatus === 'under_review'
                        ? 'Waiting for approval'
                        : 'Finish the draft, then submit it for review',
                tone: workingStatus === 'under_review' ? 'warning' : undefined,
                meta: d.publication
                    ? `Staff continue to use published version ${d.publication.version} until a replacement is approved.`
                    : 'It is not published for staff use.',
            }
          : ack.acknowledged
            ? {
                  label: `Version ${version} is the one in use`,
                  text: `You acknowledged this version${ack.acknowledged_at ? ` on ${formatDateTimeLong(ack.acknowledged_at)}` : ''}.`,
                  tone: 'success',
              }
            : {
                  label: `Next step · version ${version} is the one in use`,
                  text: ack.eligible
                      ? 'Read the steps and documents, then confirm you’ve read this version'
                      : 'Acknowledgement is not available for your current role or site.',
                  meta: d.working_revision
                      ? `Version ${d.working_revision.version} is ${d.working_revision.status === 'under_review' ? 'under review' : 'being drafted'} — until it’s approved, version ${version} is the one to follow`
                      : undefined,
              };

    /* ----- side column ----- */

    const people: PersonCardItem[] = [];
    if (d.owner)
        people.push({
            name: d.owner.name,
            sub:
                'Document owner' +
                (d.working_revision && !working
                    ? ` · working on version ${d.working_revision.version}`
                    : ''),
        });
    const approver = d.publication?.approved_by ?? d.approved_by;
    const approvedAt = d.publication?.approved_at ?? d.approved_at;
    if (approver && approver.name !== d.owner?.name)
        people.push({
            name: approver.name,
            sub: `Approved version ${d.publication?.version ?? version}`,
            foot: approvedAt ? formatDateTimeLong(approvedAt) : undefined,
        });
    else if (approver && people[0])
        people[0] = {
            ...people[0],
            sub: `${people[0].sub} · approved version ${d.publication?.version ?? version}`,
            foot: approvedAt ? formatDateTimeLong(approvedAt) : undefined,
        };

    const signOff: CheckItem[] = [
        {
            label: d.steps.length === 1 ? 'Read the step' : `Read the ${d.steps.length} steps`,
            state: ack.acknowledged ? 'success' : 'none',
        },
        {
            label:
                d.attachments.length === 1
                    ? 'Read the document'
                    : `Read the ${d.attachments.length} documents`,
            state: ack.acknowledged ? 'success' : 'none',
        },
        {
            label: 'Confirm you’ve read it',
            state: ack.acknowledged ? 'success' : 'warning',
            note: 'Each new version',
        },
    ];

    const sideSteps: { key: string; label: string; run: () => void }[] = [
        ...(d.can.edit && d.form
            ? [
                  {
                      key: 'edit',
                      label: 'Edit working revision',
                      run: () => drafts.request(() => setEditing(true)),
                  },
              ]
            : []),
        ...others.map((item) => ({
            key: item,
            label: PROCEDURE_COMMAND_LABELS[item],
            run: () => move(section, item),
        })),
    ];

    const side = (
        <>
            {people.length ? (
                <PeopleCards title="People" cols={1} items={people} />
            ) : null}
            {live ? (
                ack.eligible || ack.acknowledged ? (
                    <RingCard
                        value={ack.acknowledged ? 100 : 0}
                        label={`Your sign-off · version ${version}`}
                        sub={
                            ack.acknowledged
                                ? `You acknowledged this version${ack.acknowledged_at ? ` on ${formatDateTimeLong(ack.acknowledged_at)}` : ''}.`
                                : 'Read the steps and documents, then acknowledge this version.'
                        }
                        checks={signOff}
                    />
                ) : (
                    <QuietLine
                        title={`Your sign-off · version ${version}`}
                        text="Acknowledgement is not available for your current role or site."
                    />
                )
            ) : null}
            <Surface
                title="Next steps"
                sub={
                    live && ack.current_version_count != null
                        ? ack.current_version_count === 1
                            ? '1 person has acknowledged this version'
                            : `${ack.current_version_count} people have acknowledged this version`
                        : undefined
                }
            >
                {sideSteps.length ? (
                    <ul className="sg-list mt-2">
                        {sideSteps.map((step) => (
                            <li key={step.key}>
                                {/* eslint-disable-next-line no-restricted-syntax -- a whole list row (.sg-li) that runs its next step. */}
                                <button
                                    type="button"
                                    className="sg-li w-full text-left"
                                    onClick={step.run}
                                >
                                    <span className="sg-li-t">
                                        <b>{step.label}</b>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="sg-quiet">
                        <span>
                            {primary
                                ? 'The next step is the button at the top of the page.'
                                : 'This version is available to read. No changes are available for your current access.'}
                        </span>
                    </p>
                )}
            </Surface>
        </>
    );

    /* ----- notices (every section) ----- */

    const notices = (
        <>
            {legacy && (
                <Note icon={Info}>
                    These are captured legacy instructions. Their original
                    version or approval history is incomplete.
                    {d.viewed_revision.captured_at
                        ? ` Captured ${formatDateTimeLong(d.viewed_revision.captured_at)}.`
                        : ''}{' '}
                    A new review and approval records the next controlled
                    publication.
                </Note>
            )}
            {historical && (
                <Note tone="warning" icon={History}>
                    {d.status === 'archived'
                        ? 'This procedure is archived. These historical instructions are retained for reference and are not current.'
                        : 'You are reading a historical version. Check the current instructions before using this procedure.'}
                </Note>
            )}
            {working && (
                <Note icon={Info}>
                    This is a working revision.
                    {d.publication
                        ? ` Staff continue to use published version ${d.publication.version} until a replacement is approved.`
                        : ' It is not published for staff use.'}
                </Note>
            )}
        </>
    );

    /* ----- main ----- */

    let main: ReactNode;
    if (command) {
        main = procedureCommandAllowed(d, command) ? (
            <ProcedureCommand
                key={`${d.id}-${command}-${d.viewed_revision.version_id}`}
                detail={d}
                command={command}
                onCancel={() => move(section)}
                onSaved={saved}
            />
        ) : (
            <Surface title="Action unavailable">
                <p className="px-[18px] pt-2 pb-4 text-subtle">
                    Your access or the procedure version has changed. Return to
                    the record to see its current actions.
                </p>
                <div className="sg-actions">
                    <Button variant="outline" onClick={() => move(section)}>
                        Return to procedure
                    </Button>
                </div>
            </Surface>
        );
    } else if (section === 'overview') {
        main = <ProcedureOverview detail={d} />;
    } else if (section === 'steps') {
        main = <ProcedureSteps detail={d} />;
    } else if (section === 'ppe') {
        main = <ProcedureHazards detail={d} onSteps={() => move('steps')} />;
    } else if (section === 'applies') {
        main = <ProcedureApplies detail={d} />;
    } else if (section === 'documents') {
        main = <ProcedureDocuments detail={d} />;
    } else if (section === 'history') {
        main = <ProcedureHistory detail={d} />;
    } else {
        main = (
            <ProcedureConnections detail={d} requestDiscard={drafts.request} />
        );
    }

    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        backHref={location.returnTo}
                        icon={FileText}
                        title={d.title}
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            d.reference_number,
                            procedureCategory(d.category),
                            d.owner
                                ? `Document owner ${d.owner.name}`
                                : 'Document owner unassigned',
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            <>
                                {d.publication && !live ? (
                                    <PageHeaderGlassButton asChild>
                                        <Link
                                            href={withProcedureRequest(
                                                d.publication.href,
                                                location.request,
                                            )}
                                        >
                                            Read current instructions
                                        </Link>
                                    </PageHeaderGlassButton>
                                ) : null}
                                {d.working_revision && !working ? (
                                    <PageHeaderGlassButton asChild>
                                        <Link
                                            href={withProcedureRequest(
                                                d.working_revision.href,
                                                location.request,
                                            )}
                                        >
                                            Open working revision
                                        </Link>
                                    </PageHeaderGlassButton>
                                ) : null}
                                {primary ? (
                                    <PageHeaderPrimaryButton
                                        onClick={() => move(section, primary)}
                                    >
                                        {PROCEDURE_COMMAND_LABELS[primary]}
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            <PageHeaderStages
                                steps={steps}
                                next={next}
                                ariaLabel={`Version ${version} stage`}
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={[...SECTIONS]}
                                value={section}
                                onSelect={(item) =>
                                    move(item as ProcedureSection)
                                }
                                ariaLabel="Procedure sections"
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <div
                        ref={panel}
                        tabIndex={-1}
                        aria-label={
                            command
                                ? PROCEDURE_COMMAND_LABELS[command]
                                : SECTIONS.find((s) => s.key === section)?.label
                        }
                        className="grid min-w-0 gap-5 outline-none"
                    >
                        {notices}
                        {main}
                    </div>
                    <aside
                        className="min-w-0"
                        aria-label="Procedure people and next steps"
                    >
                        <SideColumn>{side}</SideColumn>
                    </aside>
                </div>
            </PageLayout>
            {editing && d.form && (
                <ProcedureWizardDialog
                    open
                    initial={d.form}
                    options={pickers}
                    onClose={() => drafts.request(() => setEditing(false))}
                    onOpenProcedure={() => {
                        setEditing(false);
                        router.visit(
                            procedureHref(d.id, {
                                revision: 'working',
                                returnTo: location.returnTo,
                            }),
                        );
                    }}
                />
            )}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function ProcedureOverview({ detail: d }: { detail: ProcedureWorkspaceDetail }) {
    const live = d.viewed_revision.kind === 'published';
    const request = procedureLocation(usePage().url).request;
    const facts: StoryFact[] = d.applies.sites.length
        ? [{ icon: MapPin, text: d.applies.sites.map((s) => s.name).join(' · ') }]
        : [];
    const openRequest = d.review_requests.find((r) => !r.decided_at);
    const wr = d.working_revision;
    const workingSteps: JourneyStep[] | null =
        wr && live
            ? [
                  ...(openRequest
                      ? [
                            {
                                label: 'Review requested',
                                meta:
                                    [
                                        openRequest.requested_by?.name,
                                        day(openRequest.requested_at),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || undefined,
                                state: 'done' as const,
                            },
                        ]
                      : []),
                  {
                      label: 'Draft',
                      state: wr.status === 'draft' ? 'now' : 'done',
                  },
                  {
                      label: 'Under review',
                      state: wr.status === 'under_review' ? 'now' : 'todo',
                  },
                  { label: 'Approved & published', state: 'todo' },
                  {
                      label: 'Staff sign-off',
                      meta: 'Everyone confirms again',
                      state: 'todo',
                  },
              ]
            : null;
    return (
        <>
            <Story
                title="What it’s for"
                sub={[
                    procedureCategory(d.category),
                    d.review_frequency_months
                        ? `reviewed every ${d.review_frequency_months} months`
                        : 'review cadence not recorded',
                ].join(' · ')}
                facts={facts}
                paras={[
                    {
                        h: 'Purpose',
                        t: (
                            <span className="whitespace-pre-wrap">
                                {d.purpose || 'Not recorded'}
                            </span>
                        ),
                    },
                    {
                        h: 'Scope',
                        t: (
                            <span className="whitespace-pre-wrap">
                                {d.scope || 'Not recorded'}
                            </span>
                        ),
                    },
                    ...(live &&
                    d.viewed_revision.legacy &&
                    (d.publication?.legacy_approved_by ||
                        d.publication?.legacy_approved_at)
                        ? [
                              {
                                  h: 'Legacy approval entry',
                                  t: `${d.publication?.legacy_approved_by?.name ?? 'Approver not recorded'}${d.publication?.legacy_approved_at ? ` · ${formatDateTimeLong(d.publication.legacy_approved_at)}` : ''}. This is the original record entry; it does not establish approval of this captured version.`,
                              },
                          ]
                        : []),
                ]}
            />
            {workingSteps && wr ? (
                <Surface
                    title={`Version ${wr.version} — the working revision`}
                    sub={
                        openRequest
                            ? `Asked for by ${openRequest.requested_by?.name ?? 'a reviewer'}`
                            : undefined
                    }
                    action={{
                        label: 'Open working revision',
                        href: withProcedureRequest(wr.href, request),
                    }}
                >
                    <div className="sg-journey">
                        <JourneyLine
                            steps={workingSteps}
                            label={`Version ${wr.version}`}
                        />
                    </div>
                </Surface>
            ) : null}
        </>
    );
}

function ProcedureSteps({ detail: d }: { detail: ProcedureWorkspaceDetail }) {
    if (!d.steps.length)
        return (
            <QuietLine
                title={`Steps · version ${d.viewed_revision.version}`}
                text="No steps are recorded."
            />
        );
    const items: LogItem[] = d.steps.map((step, index) => ({
        key: index,
        time: `Step ${step.step_number ?? index + 1}`,
        tone: step.safety_notes ? 'warning' : undefined,
        title: <span className="whitespace-pre-wrap">{step.description}</span>,
        sub: step.safety_notes ? (
            <span className="whitespace-pre-wrap">
                <strong>Safety note: </strong>
                {step.safety_notes}
            </span>
        ) : undefined,
    }));
    return (
        <Log
            title={`Steps · version ${d.viewed_revision.version}`}
            sub="In order · the rings mark a safety note"
            items={items}
            limit={items.length}
        />
    );
}

function ProcedureHazards({
    detail: d,
    onSteps,
}: {
    detail: ProcedureWorkspaceDetail;
    onSteps: () => void;
}) {
    const facts: StoryFact[] = [
        ...d.hazards_addressed.map((text) => ({ icon: TriangleAlert, text })),
        ...d.ppe_required.map((text) => ({
            icon: HardHat,
            text: (
                <>
                    <b>PPE</b> {text}
                </>
            ),
        })),
    ];
    const notes = d.steps
        .map((step, index) => ({ step, index }))
        .filter(({ step }) => step.safety_notes);
    return (
        <>
            <Story
                title="Hazards & PPE"
                sub="What this procedure protects against, and what to wear"
                facts={facts}
                paras={[
                    ...(d.hazards_addressed.length
                        ? []
                        : [{ h: 'Hazards addressed', t: 'Not recorded' }]),
                    ...(d.ppe_required.length
                        ? []
                        : [{ h: 'PPE required', t: 'Not recorded' }]),
                ]}
            />
            {d.emergency_procedures ? (
                <Note tone="critical" icon={ShieldAlert}>
                    <b>Emergency procedures</b> —{' '}
                    <span className="whitespace-pre-wrap">
                        {d.emergency_procedures}
                    </span>
                </Note>
            ) : (
                <QuietLine
                    title="Emergency procedures"
                    text="Not recorded"
                />
            )}
            {notes.length ? (
                <Log
                    title="Safety notes in the steps"
                    action={{ label: 'All steps', onClick: onSteps }}
                    items={notes.map(({ step, index }) => ({
                        key: index,
                        time: `Step ${step.step_number ?? index + 1}`,
                        tone: 'warning',
                        title: (
                            <span className="whitespace-pre-wrap">
                                {step.safety_notes}
                            </span>
                        ),
                        sub: <span className="line-clamp-1">{step.description}</span>,
                    }))}
                    limit={notes.length}
                />
            ) : null}
        </>
    );
}

function ProcedureApplies({ detail: d }: { detail: ProcedureWorkspaceDetail }) {
    const roles: StoryFact[] = d.applies.roles.length
        ? d.applies.roles.map((item) => ({ icon: User, text: item.label }))
        : [
              {
                  icon: User,
                  text:
                      d.applies.roles_scope === 'unknown'
                          ? 'Role applicability not recorded'
                          : d.applies.roles_scope === 'specific'
                            ? 'No role details available here'
                            : 'All eligible workforce roles',
              },
          ];
    const sites: StoryFact[] = d.applies.sites.length
        ? d.applies.sites.map((item) => ({ icon: MapPin, text: item.name }))
        : [
              {
                  icon: MapPin,
                  text:
                      d.applies.sites_scope === 'unknown'
                          ? 'Site applicability not recorded'
                          : d.applies.sites_scope === 'specific'
                            ? 'No site details available here'
                            : 'Organisation-wide workforce instructions',
              },
          ];
    return (
        <>
            <Note icon={Info}>
                Procedure acknowledgement and training completion are separate
                records.
            </Note>
            <Story
                title="Applies to"
                sub="Who follows it, where, and the training that goes with it"
                facts={[...roles, ...sites]}
                paras={[
                    {
                        h: 'Related training',
                        t: d.applies.training.length
                            ? d.applies.training
                                  .map(
                                      (item) =>
                                          `${item.name}${item.code ? ` · ${item.code}` : ''}`,
                                  )
                                  .join('; ')
                            : 'No training links are recorded for this version.',
                    },
                ]}
            />
        </>
    );
}

function ProcedureHistory({ detail: d }: { detail: ProcedureWorkspaceDetail }) {
    const request = procedureLocation(usePage().url).request;
    const versions = d.versions
        .map((v) => ({ ...v, href: withProcedureRequest(v.href, request) }))
        .sort((a, b) => a.version - b.version);
    const inUse = d.publication?.version;
    const journey: JourneyStep[] = [
        ...versions.map((v) => ({
            label: `Version ${v.version}${v.version === inUse ? ' · in use' : ''}`,
            meta:
                [v.change_summary, day(v.created_at)]
                    .filter(Boolean)
                    .join(' · ') || undefined,
            state: 'done' as const,
        })),
        ...(d.working_revision &&
        !versions.some((v) => v.version === d.working_revision!.version)
            ? [
                  {
                      label: `Version ${d.working_revision.version}`,
                      meta: procedureStatus(d.working_revision.status).label,
                      state: 'now' as const,
                  },
              ]
            : []),
    ];
    const items: LogItem[] = d.history.map((entry) => {
        const snapshot = entry.snapshot ?? null;
        const names = Array.isArray(snapshot?.names)
            ? (snapshot!.names as unknown[]).filter(
                  (name): name is string =>
                      typeof name === 'string' && name.trim() !== '',
              )
            : [];
        const recorded = versions.find((v) => v.id === snapshot?.version_id);
        const result = versions.find(
            (v) => v.id === snapshot?.result_version_id,
        );
        const outcome =
            snapshot?.outcome === 'revised'
                ? 'Revised version published'
                : snapshot?.outcome === 'no_change'
                  ? 'Reviewed without changes'
                  : null;
        const link = result && result.id !== recorded?.id ? result : recorded;
        return {
            key: entry.id,
            time: day(entry.occurred_at) ?? 'Date not recorded',
            tone: HISTORY_TONES[entry.title] ?? 'none',
            title: entry.title,
            sub: (
                <span className="whitespace-normal">
                    {[
                        entry.actor?.name ?? 'Actor not recorded',
                        entry.occurred_at ? formatTime(entry.occurred_at) : null,
                        entry.note,
                        names.length ? `Documents: ${names.join(', ')}` : null,
                        outcome,
                        entry.legacy
                            ? 'Legacy record · some provenance is incomplete.'
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            ),
            action: link
                ? {
                      label:
                          link === result
                              ? `Read resulting version ${link.version}`
                              : `Read version ${link.version}`,
                      href: link.href,
                  }
                : null,
        };
    });
    return (
        <>
            {journey.length ? (
                <Surface
                    title="Versions"
                    sub="Open a version to read it as it was"
                >
                    <div className="sg-journey">
                        <JourneyLine steps={journey} label="Versions" />
                    </div>
                </Surface>
            ) : null}
            {items.length ? (
                <Log
                    title="History"
                    sub="Newest first"
                    items={items}
                    limit={items.length}
                />
            ) : (
                <QuietLine
                    title="History"
                    text="No attributable lifecycle history is available."
                />
            )}
            <RowList
                title="Recorded versions"
                items={versions
                    .slice()
                    .reverse()
                    .map((v) => ({
                        key: v.id,
                        title: `Read version ${v.version}`,
                        sub: [
                            v.changed_by?.name ?? 'Author not recorded',
                            formatDateTimeLong(v.created_at),
                            v.change_summary,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                        badge:
                            v.version === inUse ? (
                                <StatusBadge variant="success">In use</StatusBadge>
                            ) : undefined,
                        href: v.href,
                    }))}
                empty="No recorded versions are available."
            />
        </>
    );
}

function deliveryWords(delivery: NonNullable<ProcedureRequest['delivery']>): string {
    return delivery.status === 'delivered'
        ? `Delivered to the assigned person's inbox${delivery.delivered_at ? ` on ${formatDateTimeLong(delivery.delivered_at)}` : ''}.`
        : delivery.status === 'pending'
          ? 'Awaiting delivery to the assigned person’s inbox.'
          : delivery.status === 'failed'
            ? `Delivery has not succeeded.${delivery.next_attempt_at ? ` Next retry: ${formatDateTimeLong(delivery.next_attempt_at)}.` : ''}`
            : delivery.failure_code === 'preference_suppressed'
              ? 'Not sent because assignment notices are turned off.'
              : delivery.failure_code === 'authority_changed'
                ? 'Not sent because the person’s access changed.'
                : 'Not sent because the assignment is no longer active.';
}

function ProcedureConnections({
    detail: d,
    requestDiscard,
}: {
    detail: ProcedureWorkspaceDetail;
    requestDiscard: (action: () => void) => void;
}) {
    const selectedRequest = procedureLocation(usePage().url).request;
    const selectedCard = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (selectedRequest && selectedCard.current) {
            selectedCard.current.focus({ preventScroll: true });
            selectedCard.current.scrollIntoView({ block: 'nearest' });
        }
    }, [selectedRequest]);
    return (
        <>
            <Note icon={Info}>
                Procedure review requests retain their named executor. The
                originating safety review and corrective action keep their own
                completion and verification checks.
            </Note>
            {d.review_requests.length ? (
                d.review_requests.map((item) => {
                    const decided = !!item.decided_at || !!item.outcome;
                    const steps: JourneyStep[] = [
                        {
                            label: 'Requested',
                            meta:
                                [
                                    item.requested_by?.name ?? 'Not recorded',
                                    item.requested_at
                                        ? formatDateTimeLong(item.requested_at)
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            state: 'done',
                        },
                        ...(item.delivery
                            ? [
                                  {
                                      label: 'Notice sent',
                                      meta:
                                          item.delivery.status === 'delivered'
                                              ? `${item.assigned_to?.name ?? 'Assigned person'}’s inbox`
                                              : item.delivery.status ===
                                                  'pending'
                                                ? 'Awaiting delivery'
                                                : 'Not delivered',
                                      state:
                                          item.delivery.status === 'delivered'
                                              ? ('done' as const)
                                              : ('now' as const),
                                      tone:
                                          item.delivery.status === 'failed' ||
                                          item.delivery.status === 'skipped'
                                              ? ('warning' as const)
                                              : undefined,
                                  },
                              ]
                            : []),
                        {
                            label: 'Review',
                            meta: item.assigned_to?.name ?? 'Unassigned',
                            state: decided ? 'done' : 'now',
                        },
                        {
                            label: 'Outcome recorded',
                            meta: decided
                                ? [
                                      item.decided_by?.name,
                                      item.decided_at
                                          ? day(item.decided_at)
                                          : null,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ') || undefined
                                : undefined,
                            state: decided ? 'done' : 'todo',
                        },
                    ];
                    const selected = item.id === selectedRequest;
                    return (
                        <div
                            key={item.id}
                            ref={selected ? selectedCard : undefined}
                            tabIndex={selected ? -1 : undefined}
                            aria-label={
                                selected
                                    ? 'Selected procedure review request'
                                    : undefined
                            }
                            className="grid min-w-0 gap-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Surface
                                title="Procedure review request"
                                sub={
                                    <span className="inline-flex flex-wrap items-center gap-2">
                                        <StatusBadge status={item.status} />
                                        reviewing version{' '}
                                        {item.requested_version.version}
                                    </span>
                                }
                            >
                                <div className="sg-journey">
                                    <JourneyLine
                                        steps={steps}
                                        label="Review request"
                                    />
                                    {item.delivery ? (
                                        <div className="sg-jcap">
                                            Assignment notice:{' '}
                                            {deliveryWords(item.delivery)}
                                        </div>
                                    ) : null}
                                </div>
                                <div className="grid gap-3 border-t border-dashed border-border px-[18px] py-3">
                                    <ProcedureRequestActions
                                        request={item}
                                        detail={d}
                                        requestDiscard={requestDiscard}
                                    />
                                </div>
                            </Surface>
                            <Story
                                title="What was asked"
                                facts={[
                                    {
                                        icon: ShieldAlert,
                                        text: (
                                            <Link
                                                href={item.source.href}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {item.source.reference_number ??
                                                    'Open safety review'}
                                            </Link>
                                        ),
                                    },
                                    ...(item.corrective_action
                                        ? [
                                              {
                                                  icon: Wrench,
                                                  text: (
                                                      <Link
                                                          href={
                                                              item
                                                                  .corrective_action
                                                                  .href
                                                          }
                                                          className="underline-offset-4 hover:underline"
                                                      >
                                                          {[
                                                              item
                                                                  .corrective_action
                                                                  .reference_number,
                                                              item
                                                                  .corrective_action
                                                                  .title,
                                                          ]
                                                              .filter(Boolean)
                                                              .join(' · ')}
                                                      </Link>
                                                  ),
                                              },
                                          ]
                                        : []),
                                    {
                                        icon: FileText,
                                        text: (
                                            <Link
                                                href={
                                                    item.requested_version.href
                                                }
                                                className="underline-offset-4 hover:underline"
                                            >
                                                Requested version{' '}
                                                {item.requested_version.version}
                                            </Link>
                                        ),
                                    },
                                    ...(item.result_version
                                        ? [
                                              {
                                                  icon: FileText,
                                                  text: (
                                                      <Link
                                                          href={
                                                              item
                                                                  .result_version
                                                                  .href
                                                          }
                                                          className="underline-offset-4 hover:underline"
                                                      >
                                                          Resulting version{' '}
                                                          {
                                                              item
                                                                  .result_version
                                                                  .version
                                                          }
                                                      </Link>
                                                  ),
                                              },
                                          ]
                                        : []),
                                ]}
                                paras={[
                                    {
                                        h: 'Request',
                                        t: (
                                            <span className="whitespace-pre-wrap">
                                                {item.note}
                                            </span>
                                        ),
                                    },
                                    ...(item.outcome
                                        ? [
                                              {
                                                  h: 'Outcome',
                                                  t:
                                                      item.outcome === 'revised'
                                                          ? 'Revised version published'
                                                          : 'Reviewed without changes',
                                              },
                                          ]
                                        : []),
                                    ...(item.decision_note
                                        ? [
                                              {
                                                  h: 'Decision notes',
                                                  t: (
                                                      <span className="whitespace-pre-wrap">
                                                          {item.decision_note}
                                                      </span>
                                                  ),
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                            <PeopleCards
                                title="Review"
                                cols={2}
                                items={[
                                    {
                                        name:
                                            item.assigned_to?.name ??
                                            'Unassigned',
                                        sub: 'Responsible person · does the review',
                                    },
                                    ...(item.requested_by &&
                                    item.requested_by.name !==
                                        item.assigned_to?.name
                                        ? [
                                              {
                                                  name: item.requested_by.name,
                                                  sub: 'Requested it',
                                                  foot: item.requested_at
                                                      ? formatDateTimeLong(
                                                            item.requested_at,
                                                        )
                                                      : undefined,
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </div>
                    );
                })
            ) : (
                <QuietLine
                    title="Connected work"
                    text="No linked review requests are available here."
                />
            )}
        </>
    );
}
