/**
 * Risk assessment record (approved H&S drawings pg-hsn-62, 62b–62f — Stephan
 * 23 Oct 2026): one header with the risk facts and the stage line (assessed,
 * approved, under review, review complete) carrying the next step, the five
 * sections on the rail, then each section beside the people, the review gate
 * and the other next steps. Commands open in the page (62f) and keep their own
 * payloads; the review gate's reasons show word for word.
 */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityContextMenu, EntityKebab, compactMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
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
    FactRows,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    RISK_CONSEQUENCE,
    RISK_LIKELIHOOD,
    Ring,
    RiskMap,
    RowList,
    SideColumn,
    Story,
    Surface,
    type LogItem,
    type PersonCardItem,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import {
    SafetyFormDraftContext,
    useSafetyDraftState,
    useSafetyForm,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    formatDateOnly,
    formatDateTime,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    RISK_COMMANDS,
    RISK_REGISTER,
    riskHref,
    riskLocation,
    type RiskCommand,
    type RiskSection,
} from '@/lib/health-safety/risk-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CircleDashed,
    Download,
    FileText,
    Gauge,
    History,
    Info,
    Link2,
    MapPin,
    Paperclip,
    ShieldAlert,
    Trash2,
    User,
    Zap,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    RaCommand,
    RISK_COMMAND_LABELS,
    riskCommandAllowed,
} from './ra-command';
import { cap, raStatusLabel, statusMeta } from './ra-kit';
import { RaWizardDialog } from './ra-wizard-dialog';
import type {
    RaAttachment,
    RaConnectedWork,
    RaDetail,
    RaHistoryEntry,
    RaLevel,
    RaPickers,
} from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'risk', label: 'Risk & controls', icon: Gauge },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;

const EVIDENCE_KINDS = [
    { value: 'swms', label: 'SWMS' },
    { value: 'method_statement', label: 'Method statement' },
    { value: 'photo', label: 'Photo' },
    { value: 'sds', label: 'Safety data sheet' },
    { value: 'plan', label: 'Site plan' },
    { value: 'document', label: 'Other document' },
];

/* ------------------------------------------------------------------ */
/*  Small helpers                                                      */
/* ------------------------------------------------------------------ */

/** "14 Oct 2026" for an instant, on the worker's NZ day. */
function day(iso: string | null | undefined): string | null {
    return iso ? formatDateOnly(toDateInput(iso), '') || null : null;
}

/** "14 Oct" — the stage line's short date. */
function shortDay(iso: string | null | undefined): string | null {
    return day(iso)?.replace(/ \d{4}$/, '') ?? null;
}

function levelFactTone(level: RaLevel): PageHeaderFact['tone'] {
    return level === 'extreme'
        ? 'critical'
        : level === 'high'
          ? 'warning'
          : level === 'low'
            ? 'success'
            : 'neutral';
}

/** The map cell for a likelihood and consequence (each 1–5), most likely first. */
function riskCell(
    likelihood: number | null | undefined,
    consequence: number | null | undefined,
): [number, number] | null {
    if (!likelihood || !consequence) return null;
    const row = RISK_LIKELIHOOD.length - likelihood;
    const col = consequence - 1;
    return row >= 0 && row < 5 && col >= 0 && col < 5 ? [row, col] : null;
}

function cellWords(cell: [number, number] | null): string | null {
    return cell
        ? `${RISK_LIKELIHOOD[cell[0]]} × ${RISK_CONSEQUENCE[cell[1]]}`
        : null;
}

function acceptableWords(value: boolean | null): string {
    return value == null ? 'Not recorded' : value ? 'Yes' : 'No';
}

/** The latest history entry with this title (history arrives newest first). */
function latest(d: RaDetail, title: string): RaHistoryEntry | undefined {
    return d.history?.find((entry) => entry.title === title);
}

/* ------------------------------------------------------------------ */
/*  Workspace                                                          */
/* ------------------------------------------------------------------ */

export function RaWorkspace({
    detail: d,
    pickers,
}: {
    detail: RaDetail;
    pickers: RaPickers;
}) {
    const url = usePage().url;
    const location = riskLocation(url);
    const [section, setSection] = useState<RiskSection>(location.section);
    const [command, setCommand] = useState<RiskCommand | null>(
        location.command,
    );
    const [wizard, setWizard] = useState<'edit' | 'supersede' | null>(null);
    const drafts = useSafetyWorkspaceDrafts();
    const panelRef = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const next = riskLocation(url);
        setSection(next.section);
        setCommand(next.command);
    }, [url]);
    useEffect(() => {
        if (interacted.current) panelRef.current?.focus();
    }, [section, command]);
    const move = (next: RiskSection, action: RiskCommand | null = null) =>
        drafts.request(() => {
            interacted.current = true;
            router.replace({
                url: riskHref(d.id, {
                    section: next,
                    command: action,
                    returnTo: location.returnTo,
                }),
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
            url: riskHref(d.id, { section, returnTo: location.returnTo }),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setCommand(null),
        });

    const commands = RISK_COMMANDS.filter((c) => riskCommandAllowed(d, c));
    const status = statusMeta(d.status);
    const nextCommand: RiskCommand =
        d.status === 'draft'
            ? 'approve'
            : d.status === 'under_review'
              ? d.can.complete_review
                  ? 'complete-review'
                  : 'residual'
              : 'review';
    const primary = commands.includes(nextCommand) ? nextCommand : null;
    const others = commands.filter((c) => c !== primary);
    const gate =
        d.can.manage &&
        d.status === 'under_review' &&
        !d.can.complete_review &&
        (d.review_completion_gate?.reasons.length ?? 0) > 0
            ? d.review_completion_gate!.reasons
            : [];

    /* ----- header: facts, stages, next step ----- */

    const residual =
        d.residual_risk_score != null && d.residual_risk_level
            ? { score: d.residual_risk_score, level: d.residual_risk_level }
            : null;
    const reviewDue = d.review_due_at ? formatDateOnly(d.review_due_at) : null;
    const facts: PageHeaderFact[] = [
        {
            value: `${cap(d.risk_level)} · ${d.risk_score}`,
            label: 'Risk before controls',
            tone: levelFactTone(d.risk_level),
        },
        {
            value: residual
                ? `${cap(residual.level)} · ${residual.score}`
                : 'Not recorded',
            label: 'Residual risk',
            tone: residual
                ? levelFactTone(residual.level)
                : d.status === 'under_review'
                  ? 'warning'
                  : undefined,
        },
        {
            value: reviewDue ?? 'Not set',
            label: 'Review due',
            tone:
                d.review_state.kind === 'overdue'
                    ? 'critical'
                    : d.review_state.kind === 'today' ||
                        d.review_state.kind === 'soon'
                      ? 'warning'
                      : undefined,
        },
    ];

    const requested = latest(d, 'Review requested');
    const completed = latest(d, 'Review completed');
    const requestedWords =
        d.status === 'under_review' && requested?.actor
            ? `review asked for by ${requested.actor.name}${requested.occurred_at ? `, ${shortDay(requested.occurred_at)}` : ''}`
            : null;
    const approvedMeta =
        [d.approved_by_name, shortDay(d.approved_at)]
            .filter(Boolean)
            .join(' · ') || undefined;
    const assessedMeta =
        [d.assessed_by_name, shortDay(d.assessed_at ?? d.created_at)]
            .filter(Boolean)
            .join(' · ') || undefined;
    const reviewedSince =
        d.status === 'active' &&
        completed?.occurred_at &&
        (!requested?.occurred_at ||
            completed.occurred_at >= requested.occurred_at);
    const steps: PageHeaderStage[] =
        d.status === 'superseded' || d.status === 'archived'
            ? [
                  { label: 'Assessed', meta: assessedMeta, state: 'done' },
                  {
                      label: 'Approved',
                      meta: approvedMeta,
                      state: d.approved_at ? 'done' : 'todo',
                  },
                  {
                      label: d.status === 'superseded' ? 'Replaced' : 'Archived',
                      state: 'done',
                  },
              ]
            : [
                  { label: 'Assessed', meta: assessedMeta, state: 'done' },
                  {
                      label: 'Approved',
                      meta:
                          d.status === 'draft'
                              ? 'Waiting for approval'
                              : approvedMeta,
                      state: d.status === 'draft' ? 'now' : 'done',
                  },
                  {
                      label: 'Under review',
                      meta:
                          d.status === 'under_review'
                              ? d.can.complete_review
                                  ? 'Ready to complete'
                                  : residual
                                    ? 'Checks still open'
                                    : 'Record residual risk'
                              : d.status === 'active' && reviewDue && !reviewedSince
                                ? `Due ${reviewDue}`
                                : undefined,
                      state:
                          d.status === 'under_review'
                              ? 'now'
                              : reviewedSince
                                ? 'done'
                                : 'todo',
                      tone:
                          d.status === 'under_review' ? 'warning' : undefined,
                  },
                  {
                      label: 'Review complete',
                      meta: reviewedSince
                          ? [completed?.actor?.name, shortDay(completed?.occurred_at)]
                                .filter(Boolean)
                                .join(' · ')
                          : undefined,
                      state: reviewedSince ? 'done' : 'todo',
                  },
              ];
    const versions = d.connections?.versions;
    const next: PageHeaderNextStep | null =
        d.status === 'draft'
            ? {
                  text: 'Approve and activate this assessment',
                  meta: d.assessed_by_name
                      ? `Assessed by ${d.assessed_by_name}`
                      : undefined,
              }
            : d.status === 'under_review'
              ? d.can.complete_review
                  ? {
                        label: 'Next step · to finish the review',
                        text: 'Complete the review',
                        tone: 'warning',
                        meta: [
                            'Confirm what was checked and set the next review date',
                            requestedWords,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                    }
                  : {
                        label: 'Next step · to finish the review',
                        text: residual
                            ? (d.review_completion_gate?.reasons[0] ??
                              'Complete the review')
                            : 'Record the residual risk',
                        tone: 'warning',
                        meta:
                            [
                                residual
                                    ? null
                                    : 'Likelihood and consequence, then whether it’s acceptable',
                                requestedWords,
                            ]
                                .filter(Boolean)
                                .join(' · ') || undefined,
                    }
              : d.status === 'active' && reviewDue
                ? {
                      label:
                          d.review_state.kind === 'overdue'
                              ? 'Next step · overdue'
                              : 'Next step',
                      text:
                          d.review_state.kind === 'overdue'
                              ? `Review this assessment — it was due ${reviewDue}`
                              : `Review this assessment by ${reviewDue}`,
                      tone:
                          d.review_state.kind === 'overdue'
                              ? 'critical'
                              : d.review_state.kind === 'today' ||
                                  d.review_state.kind === 'soon'
                                ? 'warning'
                                : undefined,
                      meta: d.review_frequency_days
                          ? `Reviewed every ${d.review_frequency_days} days`
                          : undefined,
                  }
                : d.status === 'superseded' && versions?.next
                  ? {
                        label: 'Replaced',
                        text: (
                            <Link
                                href={versions.next.href}
                                className="underline underline-offset-4"
                            >
                                Open the current version,{' '}
                                {versions.next.reference_number}
                            </Link>
                        ),
                    }
                  : null;

    /* ----- side column ----- */

    const people = new Map<string, { roles: string[]; foot: string[] }>();
    const person = (name: string | null | undefined, role: string, foot?: string | null) => {
        if (!name) return;
        const entry = people.get(name) ?? { roles: [], foot: [] };
        entry.roles.push(role);
        if (foot) entry.foot.push(foot);
        people.set(name, entry);
    };
    person(d.assessed_by_name, 'Assessed it', d.assessed_at ? formatDateTime(d.assessed_at) : null);
    person(
        d.created_by_name,
        'Created it',
        d.created_at
            ? d.created_by_name === d.assessed_by_name
                ? `created it ${formatTime(d.created_at)}`
                : formatDateTime(d.created_at)
            : null,
    );
    person(d.approved_by_name, 'Approved it', d.approved_at ? formatDateTime(d.approved_at) : null);
    if (d.status === 'under_review' && requested?.actor)
        person(
            requested.actor.name,
            'asked for this review',
            requested.occurred_at ? `asked ${formatDateTime(requested.occurred_at)}` : null,
        );
    const peopleItems: PersonCardItem[] = [...people.entries()].map(
        ([name, entry]) => ({
            name,
            sub: entry.roles
                .filter((role, i) => role !== 'Created it' || i === 0)
                .join(' · '),
            foot: entry.foot.join(' · ') || undefined,
        }),
    );

    const sideSteps: { key: string; label: string; run: () => void }[] = [
        ...(d.can.edit
            ? [
                  {
                      key: 'edit',
                      label: 'Edit draft',
                      run: () => drafts.request(() => setWizard('edit')),
                  },
              ]
            : []),
        ...others.map((c) => ({
            key: c,
            label: RISK_COMMAND_LABELS[c],
            run: () => move(section, c),
        })),
        ...(d.can.supersede
            ? [
                  {
                      key: 'supersede',
                      label: 'Start a new version',
                      run: () => drafts.request(() => setWizard('supersede')),
                  },
              ]
            : []),
    ];

    const side = (
        <>
            {peopleItems.length ? (
                <PeopleCards
                    title="People"
                    sub={d.updated_at ? `Last updated ${formatDateTime(d.updated_at)}` : undefined}
                    cols={1}
                    items={peopleItems}
                />
            ) : null}
            {gate.length ? (
                <Note tone="warning" icon={AlertTriangle}>
                    <p className="font-medium">Before completing review</p>
                    <ul className="mt-1 list-disc space-y-1 pl-5">
                        {gate.map((reason) => (
                            <li key={reason}>{reason}</li>
                        ))}
                    </ul>
                </Note>
            ) : null}
            <Surface
                title="Next steps"
                sub="Owners and due dates for linked reviews and actions are under Connected work."
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
                        <Ring tone="none" />
                        <span>
                            {primary
                                ? 'The next step is the button at the top of the page.'
                                : 'No changes are available with your access and this assessment’s current state.'}
                        </span>
                    </p>
                )}
            </Surface>
        </>
    );

    /* ----- main ----- */

    let main: ReactNode;
    if (command) {
        main = riskCommandAllowed(d, command) ? (
            <>
                <RaCommand
                    key={command}
                    detail={d}
                    command={command}
                    onCancel={() => move(section)}
                    onSaved={saved}
                />
                {command === 'residual' ? (
                    <RiskWhere detail={d} note="before" />
                ) : null}
            </>
        ) : (
            <>
                <Note icon={Info}>
                    This action is unavailable in the assessment’s current
                    state or with your current access.
                </Note>
                <div>
                    <Button onClick={() => move(section)}>
                        Back to assessment
                    </Button>
                </div>
            </>
        );
    } else if (section === 'overview') {
        main = <RiskOverview detail={d} />;
    } else if (section === 'risk') {
        main = (
            <>
                <RiskWhere detail={d} />
                <Controls detail={d} />
            </>
        );
    } else if (section === 'evidence') {
        main = <RiskEvidence detail={d} />;
    } else if (section === 'history') {
        main = <RiskHistory detail={d} />;
    } else {
        main = <RiskConnections detail={d} />;
    }

    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        backHref={location.returnTo}
                        icon={ShieldAlert}
                        title={d.title}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    status.tone === 'info'
                                        ? 'neutral'
                                        : status.tone
                                }
                            >
                                {raStatusLabel(d.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={`${d.reference_number} · ${d.attached_to.name}`}
                        actions={
                            primary ? (
                                <PageHeaderPrimaryButton
                                    onClick={() => move(section, primary)}
                                >
                                    {RISK_COMMAND_LABELS[primary]}
                                </PageHeaderPrimaryButton>
                            ) : undefined
                        }
                        facts={<PageHeaderFacts items={facts} />}
                        strip={
                            <PageHeaderStages
                                steps={steps}
                                next={next}
                                ariaLabel="Risk assessment stage"
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={[...SECTIONS]}
                                value={section}
                                ariaLabel="Risk assessment sections"
                                onSelect={(key) => move(key as RiskSection)}
                            />
                        }
                    />
                }
            >
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <div
                        ref={panelRef}
                        tabIndex={-1}
                        className="grid min-w-0 gap-5 focus:outline-none"
                        aria-label={
                            command
                                ? RISK_COMMAND_LABELS[command]
                                : SECTIONS.find((s) => s.key === section)?.label
                        }
                    >
                        {main}
                    </div>
                    <aside
                        className="min-w-0"
                        aria-label="Risk assessment people and next steps"
                    >
                        <SideColumn>{side}</SideColumn>
                    </aside>
                </div>
            </PageLayout>
            {wizard && (
                <RaWizardDialog
                    key={`${wizard}:${d.id}`}
                    kind={wizard}
                    detail={d}
                    pickers={pickers}
                    onClose={() => drafts.request(() => setWizard(null))}
                    onSuccess={(id) => {
                        setWizard(null);
                        if (id)
                            router.visit(
                                riskHref(id, { returnTo: location.returnTo }),
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

function RiskOverview({ detail: d }: { detail: RaDetail }) {
    const AttachIcon =
        d.attached_to.type === 'client'
            ? User
            : d.attached_to.type === 'event'
              ? Zap
              : d.attached_to.type === 'site'
                ? MapPin
                : CircleDashed;
    const newer = d.connections?.versions.next;
    return (
        <>
            {newer ? (
                <Note icon={Info}>
                    A newer assessment is available.{' '}
                    <Link className="underline" href={newer.href}>
                        Open {newer.reference_number}
                    </Link>
                </Note>
            ) : null}
            <Story
                title="What it covers"
                sub={
                    d.review_frequency_days == null
                        ? 'Review frequency not set'
                        : `Reviewed every ${d.review_frequency_days} days`
                }
                facts={[{ icon: AttachIcon, text: d.attached_to.name }]}
                paras={[
                    { h: 'The risk', t: d.risk_description || 'Not recorded' },
                    { h: 'Approval note', t: d.approval_note || 'Not recorded' },
                    {
                        h: 'Latest residual or review note',
                        t: d.last_review_note || 'Not recorded',
                    },
                ]}
            />
            <Controls detail={d} />
        </>
    );
}

function Controls({ detail: d }: { detail: RaDetail }) {
    return (
        <Story
            title="Controls"
            paras={[
                {
                    h: 'Existing controls',
                    t: (
                        <span className="whitespace-pre-wrap">
                            {d.existing_controls || 'Not recorded'}
                        </span>
                    ),
                },
                {
                    h: 'Additional controls',
                    t: (
                        <span className="whitespace-pre-wrap">
                            {d.additional_controls || 'Not recorded'}
                        </span>
                    ),
                },
            ]}
        />
    );
}

/** Where the risk sits on the map, before or after controls. */
function RiskWhere({
    detail: d,
    note: only,
}: {
    detail: RaDetail;
    /** "before": the command pane — mark only the risk before controls. */
    note?: 'before';
}) {
    const before = riskCell(d.likelihood, d.consequence);
    const after = riskCell(d.residual_likelihood, d.residual_consequence);
    const [view, setView] = useState(0);
    const mark = view === 1 ? after : before;
    const note =
        only === 'before' ? (
            'The outlined square is this risk before controls'
        ) : view === 1 ? (
            after ? (
                <>
                    <b>{cellWords(after)}</b> after controls ={' '}
                    {d.residual_risk_score}, {d.residual_risk_level} ·
                    acceptable: {acceptableWords(d.risk_acceptable).toLowerCase()}
                </>
            ) : (
                'Residual risk not recorded'
            )
        ) : (
            <>
                <b>{cellWords(before)}</b> before controls = {d.risk_score},{' '}
                {d.risk_level} ·{' '}
                {after
                    ? `after controls ${d.residual_risk_score}, ${d.residual_risk_level}`
                    : 'residual risk not recorded'}{' '}
                · acceptable: {acceptableWords(d.risk_acceptable).toLowerCase()}
            </>
        );
    return (
        <RiskMap
            title="Where it sits"
            sub="Likelihood × consequence"
            views={only === 'before' ? ['Before controls'] : ['Before controls', 'After controls']}
            view={only === 'before' ? 0 : view}
            onView={setView}
            mark={mark}
            note={note}
        />
    );
}

function RiskEvidence({ detail: d }: { detail: RaDetail }) {
    const [uploadState, setUploadState] = useState({
        dirty: false,
        busy: false,
    });
    useSafetyDraftState(uploadState);
    const removal = useSafetyForm({});
    const [selected, setSelected] = useState<number | null>(null);
    const attachment = d.attachments.find((a) => a.id === selected);
    const fileMenu = useEntityContextMenu<RaAttachment>();
    const menuFor = (a: RaAttachment): MenuItem[] =>
        compactMenu([
            {
                label: 'Download',
                icon: Download,
                onClick: () => window.location.assign(a.download_url),
            },
            d.can.remove_evidence && { separator: true },
            d.can.remove_evidence && {
                label: `Remove ${a.original_name}`,
                icon: Trash2,
                danger: true,
                onClick: () => setSelected(a.id),
            },
        ]);
    const kindLabel = (kind: string | null) =>
        kind
            ? (EVIDENCE_KINDS.find((k) => k.value === kind)?.label ??
              cap(kind.replaceAll('_', ' ')))
            : null;
    return (
        <>
            <ConfirmDialog
                open={Boolean(attachment)}
                onClose={() => { if (!removal.processing) setSelected(null); }}
                processing={removal.processing}
                variant="destructive"
                title={`Remove ${attachment?.original_name ?? 'this file'}?`}
                description="It comes off this risk assessment and can’t be put back. The history still shows it was there and who removed it."
                confirmText="Remove evidence"
                cancelText="Keep evidence"
                onConfirm={() => {
                    if (!attachment) return;
                    removal.delete(`${RISK_REGISTER}/${d.id}/attachments/${attachment.id}`, {
                        preserveScroll: true,
                        onSuccess: () => setSelected(null),
                    });
                }}
            />
            {Object.keys(removal.errors).length > 0 && (
                <Note tone="critical" icon={AlertTriangle}>
                    {Object.values(removal.errors).join(' ')}
                </Note>
            )}
            <Surface
                title="Supporting files"
                sub="Removing a file shows in History"
            >
                {d.attachments.length ? (
                    <ul className="sg-list mt-2">
                        {d.attachments.map((a) => (
                            <li
                                key={a.id}
                                className="flex items-center"
                                onContextMenu={(event) => fileMenu.open(event, a)}
                            >
                                <a href={a.download_url} className="sg-li min-w-0 flex-1">
                                    <span className="sg-li-lead" aria-hidden="true">
                                        <FileText className="size-4" />
                                    </span>
                                    <span className="sg-li-t">
                                        <b className="break-all">{a.original_name}</b>
                                        <small className="!whitespace-normal">
                                            {[
                                                a.uploaded_by_name ??
                                                    'Uploader not recorded',
                                                formatDateTime(a.created_at),
                                                kindLabel(a.kind),
                                                a.notes,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </small>
                                    </span>
                                </a>
                                <EntityKebab
                                    actions={menuFor(a)}
                                    label={`Actions for ${a.original_name}`}
                                    className="mr-3"
                                />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>No evidence is recorded.</span>
                    </p>
                )}
            </Surface>
            {d.can.add_evidence && (
                <Surface title="Add evidence">
                    <div className="px-[18px] pt-3 pb-4">
                        <AttachmentUploader
                            endpoint={`${RISK_REGISTER}/${d.id}/attachments`}
                            noteField="notes"
                            onStateChange={setUploadState}
                            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                            hint="Images, PDF, Word or Excel — up to 20 MB each"
                            categoryField={{
                                field: 'kind',
                                label: 'Evidence type',
                                options: EVIDENCE_KINDS,
                            }}
                        />
                    </div>
                </Surface>
            )}
            {fileMenu.ctx ? (
                <EntityContextMenu
                    x={fileMenu.ctx.x}
                    y={fileMenu.ctx.y}
                    icon={FileText}
                    title={fileMenu.ctx.record.original_name}
                    items={menuFor(fileMenu.ctx.record)}
                    onClose={fileMenu.close}
                />
            ) : null}
        </>
    );
}

const HISTORY_TONES: Record<string, SgTone> = {
    'Review requested': 'warning',
    'Assessment activated': 'success',
    'Review completed': 'success',
    'Residual risk recorded': 'info',
    'Evidence added': 'info',
    'Evidence removed': 'warning',
    'Draft updated': 'info',
};

function snapshotValue(value: RaHistoryEntry['snapshot'][string]): string {
    return value == null
        ? 'Not recorded'
        : typeof value === 'boolean'
          ? value
              ? 'Yes'
              : 'No'
          : Array.isArray(value)
            ? value.join(', ')
            : String(value);
}

export function RiskHistory({ detail: d }: { detail: RaDetail }) {
    const [open, setOpen] = useState<string | null>(null);
    const history = d.history ?? [];
    if (!history.length)
        return (
            <QuietLine
                title="History"
                text="No recorded history is available here. Assessor and approval details remain in Overview."
            />
        );
    const shown = history.find((entry) => entry.id === open);
    const fields = (entry: RaHistoryEntry) =>
        Object.entries(entry.snapshot).filter(
            ([key]) =>
                !['approval_note', 'last_review_note', 'names'].includes(key),
        );
    const items: LogItem[] = history.map((entry) => {
        const names = Array.isArray(entry.snapshot.names)
            ? (entry.snapshot.names as string[]).join(', ')
            : null;
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
                        names,
                        entry.legacy
                            ? 'Older audit entry: the original record may contain fewer details.'
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            ),
            action: fields(entry).length
                ? {
                      label: open === entry.id ? 'Hide' : 'View as it was',
                      onClick: () =>
                          setOpen((current) =>
                              current === entry.id ? null : entry.id,
                          ),
                  }
                : null,
        };
    });
    return (
        <>
            <Log
                title="History"
                sub="Each entry shows the assessment as it was then"
                items={items}
                limit={items.length}
            />
            {shown ? (
                <FactRows
                    aria-label="Recorded assessment"
                    title={`As it was · ${shown.title}`}
                    sub={formatDateTime(shown.occurred_at)}
                    rows={fields(shown).map(
                        ([key, value]) =>
                            [
                                cap(key.replaceAll('_', ' ')),
                                snapshotValue(value),
                            ] as [string, string],
                    )}
                />
            ) : null}
        </>
    );
}

function workTone(item: RaConnectedWork): SgTone {
    const state = (item.display_state ?? item.status).toLowerCase();
    if (['completed', 'closed', 'verified', 'done', 'cancelled'].includes(state))
        return 'success';
    const today = toDateInput(new Date());
    if (item.due_date && item.due_date < today) return 'warning';
    return 'info';
}

function RiskConnections({ detail: d }: { detail: RaDetail }) {
    const c = d.connections;
    const state = (item: { status: string; display_state?: string | null }) =>
        cap((item.display_state ?? item.status).replaceAll('_', ' ')).toLowerCase();
    const work = [
        ...(c?.tasks ?? []).map((item) => ({ kind: 'Task', item })),
        ...(c?.corrective_actions ?? []).map((item) => ({
            kind: 'Corrective action',
            item,
        })),
    ].sort((a, b) =>
        (a.item.due_date ?? '9999') < (b.item.due_date ?? '9999') ? -1 : 1,
    );
    const items: LogItem[] = [
        ...work.map(({ kind, item }) => ({
            key: `${kind}-${item.id}`,
            time: item.due_date
                ? formatDateOnly(item.due_date).replace(/ \d{4}$/, '')
                : 'No due date',
            tone: workTone(item),
            title: (
                <Link
                    href={item.href}
                    className="underline-offset-4 hover:underline"
                >
                    {item.title}
                </Link>
            ),
            sub: [
                item.reference_number ? `${kind} ${item.reference_number}` : kind,
                item.assigned_to?.name ?? 'Unassigned',
                state(item),
            ].join(' · '),
        })),
        ...(c?.safety_review
            ? [
                  {
                      key: 'review',
                      time: cap(c.safety_review.status.replaceAll('_', ' ')),
                      tone: 'info' as SgTone,
                      title: (
                          <Link
                              href={c.safety_review.href}
                              className="underline-offset-4 hover:underline"
                          >
                              Safety review {c.safety_review.reference_number}
                          </Link>
                      ),
                      sub: `Review owner: ${c.safety_review.owner?.name ?? 'Unassigned'}`,
                  },
              ]
            : []),
    ];
    const sub = (
        <>
            {c?.source ? (
                <>
                    Applies to{' '}
                    <Link href={c.source.href} className="underline">
                        {c.source.title}
                    </Link>{' '}
                    ·{' '}
                </>
            ) : null}
            Each linked record keeps its own owner, permissions and completion
            checks.
        </>
    );
    const versionRows = c
        ? [...c.versions.previous, ...(c.versions.next ? [c.versions.next] : [])]
        : [];
    return (
        <>
            {items.length ? (
                <Log
                    title="Linked work"
                    sub={sub}
                    action={
                        c?.tasks_href
                            ? { label: 'Open Tasks', href: c.tasks_href }
                            : null
                    }
                    items={items}
                    limit={items.length}
                />
            ) : (
                <QuietLine
                    title="Linked work"
                    sub={sub}
                    text="No linked records available here."
                    action={
                        c?.tasks_href
                            ? { label: 'Open Tasks', href: c.tasks_href }
                            : null
                    }
                />
            )}
            {versionRows.length ? (
                <RowList
                    title="Assessment versions"
                    items={versionRows.map((v) => ({
                        key: v.id,
                        title: v.reference_number,
                        sub: raStatusLabel(v.status),
                        href: v.href,
                    }))}
                />
            ) : (
                <QuietLine
                    title="Assessment versions"
                    text="No other versions available here."
                />
            )}
        </>
    );
}
