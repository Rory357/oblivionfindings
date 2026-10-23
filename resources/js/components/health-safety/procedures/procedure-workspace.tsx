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
import { StatusBadge } from '@/components/ui/status-badge';
import { InfoCard } from '@/components/wizard/primitives';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
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
    Paperclip,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ProcedureWizardDialog } from '../procedure-wizard-dialog';
import {
    PROCEDURE_COMMAND_LABELS,
    ProcedureCommand,
    procedureCommandAllowed,
} from './procedure-command';
import { ProcedureDocuments } from './procedure-documents';
import {
    ProcedureReviewDate,
    procedureCategory,
    procedureStatus,
} from './procedure-kit';
import { ProcedureRequestActions } from './procedure-request-actions';
import type { ProcedurePickers, ProcedureWorkspaceDetail } from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'steps', label: 'Steps', icon: ListChecks },
    { key: 'ppe', label: 'Hazards & PPE', icon: HardHat },
    { key: 'applies', label: 'Applies to', icon: Users },
    { key: 'documents', label: 'Documents', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
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
    const status = procedureStatus(d.status, d.viewed_revision.legacy);
    const commands = PROCEDURE_COMMANDS.filter((item) =>
        procedureCommandAllowed(d, item),
    );
    const live = d.viewed_revision.kind === 'published';
    const historical = d.viewed_revision.kind === 'historical';
    const revisionLabel = historical
        ? 'Historical version'
        : live
          ? d.viewed_revision.legacy
              ? 'Captured current instructions'
              : 'Published version'
          : 'Working revision';
    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        backHref={location.returnTo}
                        icon={FileText}
                        title={d.reference_number ?? 'Safe work procedure'}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    status.variant === 'info'
                                        ? 'neutral'
                                        : status.variant
                                }
                            >
                                {status.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${d.title} · ${procedureCategory(d.category)}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Viewing"
                                    href={href('overview')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.viewed_revision.version}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {revisionLabel}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Steps"
                                    href={href('steps')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.steps.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Instructions in this version
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Documents"
                                    href={href('documents')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.attachments.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Files in this version
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="History"
                                    href={href('history')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.versions.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Available recorded versions
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[...SECTIONS]}
                                value={section}
                                onSelect={(next) =>
                                    move(next as ProcedureSection)
                                }
                                ariaLabel="Procedure sections"
                            />
                        }
                    />
                }
            >
                <div className="space-y-5">
                    <div
                        className="flex flex-wrap items-center gap-3"
                        aria-label="Procedure versions"
                    >
                        <StatusBadge
                            variant={
                                historical || d.viewed_revision.legacy
                                    ? 'neutral'
                                    : live
                                      ? 'success'
                                      : 'warning'
                            }
                        >
                            {revisionLabel} {d.viewed_revision.version}
                        </StatusBadge>
                        {d.publication && !live && (
                            <Link
                                className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                href={withProcedureRequest(
                                    d.publication.href,
                                    location.request,
                                )}
                            >
                                Read current instructions · version{' '}
                                {d.publication.version}
                            </Link>
                        )}
                        {d.working_revision &&
                            d.viewed_revision.kind !== 'working' && (
                                <Link
                                    className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                    href={withProcedureRequest(
                                        d.working_revision.href,
                                        location.request,
                                    )}
                                >
                                    Open working revision · version{' '}
                                    {d.working_revision.version}
                                </Link>
                            )}
                    </div>
                    {d.viewed_revision.legacy && (
                        <InfoCard icon={Info}>
                            These are captured legacy instructions. Their
                            original version or approval history is incomplete.
                            {d.viewed_revision.captured_at
                                ? ` Captured ${formatDateTimeLong(d.viewed_revision.captured_at)}.`
                                : ''}{' '}
                            A new review and approval records the next
                            controlled publication.
                        </InfoCard>
                    )}
                    {historical && (
                        <InfoCard icon={History}>
                            {d.status === 'archived'
                                ? 'This procedure is archived. These historical instructions are retained for reference and are not current.'
                                : 'You are reading a historical version. Check the current instructions before using this procedure.'}
                        </InfoCard>
                    )}
                    {!live && !historical && (
                        <InfoCard icon={Info}>
                            This is a working revision.
                            {d.publication
                                ? ` Staff continue to use published version ${d.publication.version} until a replacement is approved.`
                                : ' It is not published for staff use.'}
                        </InfoCard>
                    )}
                    <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <Card className="min-w-0">
                            <CardContent>
                                <div
                                    ref={panel}
                                    tabIndex={-1}
                                    className="space-y-5 outline-none"
                                >
                                    {command ? (
                                        procedureCommandAllowed(d, command) ? (
                                            <ProcedureCommand
                                                key={`${d.id}-${command}-${d.viewed_revision.version_id}`}
                                                detail={d}
                                                command={command}
                                                onCancel={() => move(section)}
                                                onSaved={saved}
                                            />
                                        ) : (
                                            <div className="space-y-3">
                                                <h2 className="text-section-title">
                                                    Action unavailable
                                                </h2>
                                                <p className="text-subtle">
                                                    Your access or the procedure
                                                    version has changed. Return
                                                    to the record to see its
                                                    current actions.
                                                </p>
                                                <Button
                                                    className="frontline-tap"
                                                    variant="outline"
                                                    onClick={() =>
                                                        move(section)
                                                    }
                                                >
                                                    Return to procedure
                                                </Button>
                                            </div>
                                        )
                                    ) : (
                                        <>
                                            {section === 'overview' && (
                                                <>
                                                    <h2 className="text-section-title">
                                                        {d.title}
                                                    </h2>
                                                    <ProcedureReviewDate
                                                        date={d.review_date}
                                                    />
                                                    <TextBlock
                                                        title="Purpose"
                                                        value={d.purpose}
                                                    />
                                                    <TextBlock
                                                        title="Scope"
                                                        value={d.scope}
                                                    />
                                                    <dl className="grid gap-4 sm:grid-cols-2">
                                                        <Fact label="Document owner">
                                                            {d.owner?.name ??
                                                                'Unassigned'}
                                                        </Fact>
                                                        <Fact label="Next review">
                                                            {formatDateOnly(
                                                                d.review_date,
                                                                'Not scheduled',
                                                            )}
                                                        </Fact>
                                                        <Fact label="Review cadence">
                                                            {d.review_frequency_months
                                                                ? `Every ${d.review_frequency_months} months`
                                                                : 'Not recorded'}
                                                        </Fact>
                                                        <Fact label="Approval recorded by">
                                                            {d.approved_by
                                                                ?.name ??
                                                                'Not recorded'}
                                                            {d.approved_at
                                                                ? ` · ${formatDateTimeLong(d.approved_at)}`
                                                                : ''}
                                                        </Fact>
                                                    </dl>
                                                    {live &&
                                                        d.viewed_revision
                                                            .legacy &&
                                                        (d.publication
                                                            ?.legacy_approved_by ||
                                                            d.publication
                                                                ?.legacy_approved_at) && (
                                                            <p className="text-subtle">
                                                                Legacy approval
                                                                entry:{' '}
                                                                {d.publication
                                                                    ?.legacy_approved_by
                                                                    ?.name ??
                                                                    'Approver not recorded'}
                                                                {d.publication
                                                                    ?.legacy_approved_at
                                                                    ? ` · ${formatDateTimeLong(d.publication.legacy_approved_at)}`
                                                                    : ''}
                                                                . This is the
                                                                original record
                                                                entry; it does
                                                                not establish
                                                                approval of this
                                                                captured
                                                                version.
                                                            </p>
                                                        )}
                                                    {live && (
                                                        <p className="text-subtle">
                                                            {d.acknowledgement
                                                                .acknowledged
                                                                ? `You acknowledged this version${d.acknowledgement.acknowledged_at ? ` on ${formatDateTimeLong(d.acknowledgement.acknowledged_at)}` : ''}.`
                                                                : d
                                                                        .acknowledgement
                                                                        .eligible
                                                                  ? 'Read the steps and documents, then acknowledge this version.'
                                                                  : 'Acknowledgement is not available for your current role or site.'}
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                            {section === 'steps' && (
                                                <>
                                                    <h2 className="text-section-title">
                                                        Steps
                                                    </h2>
                                                    {d.steps.length ? (
                                                        <ol className="space-y-4">
                                                            {d.steps.map(
                                                                (
                                                                    step,
                                                                    index,
                                                                ) => (
                                                                    <li
                                                                        className="space-y-2 rounded-lg border border-border p-4"
                                                                        key={
                                                                            index
                                                                        }
                                                                    >
                                                                        <h3 className="font-semibold">
                                                                            Step{' '}
                                                                            {
                                                                                step.step_number
                                                                            }
                                                                        </h3>
                                                                        <p className="whitespace-pre-wrap">
                                                                            {
                                                                                step.description
                                                                            }
                                                                        </p>
                                                                        {step.safety_notes && (
                                                                            <p className="rounded-lg bg-status-warning-bg p-3 text-sm whitespace-pre-wrap text-status-warning-foreground">
                                                                                <strong>
                                                                                    Safety
                                                                                    note:{' '}
                                                                                </strong>
                                                                                {
                                                                                    step.safety_notes
                                                                                }
                                                                            </p>
                                                                        )}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ol>
                                                    ) : (
                                                        <p className="text-subtle">
                                                            No steps are
                                                            recorded.
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                            {section === 'ppe' && (
                                                <>
                                                    <h2 className="text-section-title">
                                                        Hazards & PPE
                                                    </h2>
                                                    <TextList
                                                        title="Hazards addressed"
                                                        items={
                                                            d.hazards_addressed
                                                        }
                                                    />
                                                    <TextList
                                                        title="PPE required"
                                                        items={d.ppe_required}
                                                    />
                                                    <TextBlock
                                                        title="Emergency procedures"
                                                        value={
                                                            d.emergency_procedures
                                                        }
                                                    />
                                                </>
                                            )}
                                            {section === 'applies' && (
                                                <>
                                                    <h2 className="text-section-title">
                                                        Applies to
                                                    </h2>
                                                    <TextList
                                                        title="Roles"
                                                        items={d.applies.roles.map(
                                                            (item) =>
                                                                item.label,
                                                        )}
                                                        empty={
                                                            d.applies
                                                                .roles_scope ===
                                                            'unknown'
                                                                ? 'Role applicability not recorded'
                                                                : d.applies
                                                                        .roles_scope ===
                                                                    'specific'
                                                                  ? 'No role details available here'
                                                                  : 'All eligible workforce roles'
                                                        }
                                                    />
                                                    <TextList
                                                        title="Sites"
                                                        items={d.applies.sites.map(
                                                            (item) => item.name,
                                                        )}
                                                        empty={
                                                            d.applies
                                                                .sites_scope ===
                                                            'unknown'
                                                                ? 'Site applicability not recorded'
                                                                : d.applies
                                                                        .sites_scope ===
                                                                    'specific'
                                                                  ? 'No site details available here'
                                                                  : 'Organisation-wide workforce instructions'
                                                        }
                                                    />
                                                    <TextList
                                                        title="Related training"
                                                        items={d.applies.training.map(
                                                            (item) =>
                                                                `${item.name}${item.code ? ` · ${item.code}` : ''}`,
                                                        )}
                                                        empty="No training links are recorded for this version."
                                                    />
                                                    <p className="text-subtle">
                                                        Procedure
                                                        acknowledgement and
                                                        training completion are
                                                        separate records.
                                                    </p>
                                                </>
                                            )}
                                            {section === 'documents' && (
                                                <ProcedureDocuments
                                                    detail={d}
                                                />
                                            )}
                                            {section === 'history' && (
                                                <ProcedureHistory detail={d} />
                                            )}
                                            {section === 'connections' && (
                                                <ProcedureConnections
                                                    detail={d}
                                                    requestDiscard={
                                                        drafts.request
                                                    }
                                                />
                                            )}
                                        </>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                        <Card
                            className={
                                commands.length && !command
                                    ? 'order-first xl:order-last'
                                    : ''
                            }
                        >
                            <CardHeader>
                                <CardTitle>Next steps</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {d.can.edit && d.form && (
                                    <Button
                                        className="frontline-tap w-full justify-start"
                                        onClick={() =>
                                            drafts.request(() =>
                                                setEditing(true),
                                            )
                                        }
                                    >
                                        Edit working revision
                                    </Button>
                                )}
                                {commands.map((item) => (
                                    <Button
                                        key={item}
                                        className="frontline-tap w-full justify-start text-left whitespace-normal"
                                        variant={
                                            item === 'acknowledge'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => move(section, item)}
                                    >
                                        {PROCEDURE_COMMAND_LABELS[item]}
                                    </Button>
                                ))}
                                {!commands.length && !d.can.edit && (
                                    <p className="text-subtle">
                                        This version is available to read. No
                                        changes are available for your current
                                        access.
                                    </p>
                                )}
                                <p className="text-subtle">
                                    Document owner:{' '}
                                    {d.owner?.name ?? 'Unassigned'}
                                </p>
                            </CardContent>
                        </Card>
                    </div>
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
function TextBlock({ title, value }: { title: string; value: string | null }) {
    return (
        <section className="space-y-2">
            <h3 className="font-semibold">{title}</h3>
            <p className={value ? 'whitespace-pre-wrap' : 'text-subtle'}>
                {value || 'Not recorded'}
            </p>
        </section>
    );
}
function TextList({
    title,
    items,
    empty = 'Not recorded',
}: {
    title: string;
    items: string[];
    empty?: string;
}) {
    return (
        <section className="space-y-2">
            <h3 className="font-semibold">{title}</h3>
            {items.length ? (
                <ul className="list-inside list-disc space-y-2">
                    {items.map((item, i) => (
                        <li className="break-words" key={i}>
                            {item}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">{empty}</p>
            )}
        </section>
    );
}
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-subtle">{label}</dt>
            <dd className="mt-1 text-sm break-words">{children}</dd>
        </div>
    );
}
function ProcedureHistory({ detail: d }: { detail: ProcedureWorkspaceDetail }) {
    const request = procedureLocation(usePage().url).request;
    const historyVersions = d.versions.map((version) => ({
        ...version,
        href: withProcedureRequest(version.href, request),
    }));
    return (
        <>
            <h2 className="text-section-title">History</h2>
            {d.history.length ? (
                <ol className="space-y-3">
                    {d.history.map((entry) => (
                        <li
                            key={entry.id}
                            className="space-y-2 rounded-lg border border-border p-4"
                        >
                            <h3 className="font-semibold">{entry.title}</h3>
                            <p className="text-subtle">
                                {entry.actor?.name ?? 'Actor not recorded'} ·{' '}
                                {formatDateTimeLong(entry.occurred_at)}
                            </p>
                            {entry.note && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {entry.note}
                                </p>
                            )}
                            <ProcedureHistoryFacts
                                entry={entry}
                                versions={historyVersions}
                            />
                            {entry.legacy && (
                                <p className="text-subtle">
                                    Legacy record · some provenance is
                                    incomplete.
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-subtle">
                    No attributable lifecycle history is available.
                </p>
            )}
            <section className="space-y-3">
                <h3 className="font-semibold">Recorded versions</h3>
                <ul className="space-y-3">
                    {d.versions.map((version) => (
                        <li
                            className="rounded-lg border border-border p-3"
                            key={version.id}
                        >
                            <Link
                                className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                href={withProcedureRequest(
                                    version.href,
                                    request,
                                )}
                            >
                                Read version {version.version}
                            </Link>
                            <p className="text-subtle">
                                {version.changed_by?.name ??
                                    'Author not recorded'}{' '}
                                · {formatDateTimeLong(version.created_at)}
                            </p>
                            {version.change_summary && (
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {version.change_summary}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
        </>
    );
}
function ProcedureHistoryFacts({
    entry,
    versions,
}: {
    entry: ProcedureWorkspaceDetail['history'][number];
    versions: ProcedureWorkspaceDetail['versions'];
}) {
    const snapshot = entry.snapshot;
    if (!snapshot) return null;
    const names = Array.isArray(snapshot.names)
        ? snapshot.names.filter(
              (name): name is string =>
                  typeof name === 'string' && name.trim() !== '',
          )
        : [];
    const recorded = versions.find(
        (version) => version.id === snapshot.version_id,
    );
    const result = versions.find(
        (version) => version.id === snapshot.result_version_id,
    );
    const outcome =
        snapshot.outcome === 'revised'
            ? 'Revised version published'
            : snapshot.outcome === 'no_change'
              ? 'Reviewed without changes'
              : null;
    return (
        <div className="space-y-2 text-sm">
            {names.length > 0 && (
                <p className="break-words">Documents: {names.join(', ')}</p>
            )}
            {outcome && <p>{outcome}</p>}
            {recorded && (
                <Link
                    className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                    href={recorded.href}
                >
                    Read recorded version {recorded.version}
                </Link>
            )}
            {result && result.id !== recorded?.id && (
                <Link
                    className="frontline-tap frontline-focus flex items-center text-primary underline"
                    href={result.href}
                >
                    Read resulting version {result.version}
                </Link>
            )}
        </div>
    );
}
function ProcedureConnections({
    detail: d,
    requestDiscard,
}: {
    detail: ProcedureWorkspaceDetail;
    requestDiscard: (action: () => void) => void;
}) {
    const selectedRequest = procedureLocation(usePage().url).request;
    const selectedCard = useRef<HTMLLIElement>(null);
    useEffect(() => {
        if (selectedRequest && selectedCard.current) {
            selectedCard.current.focus({ preventScroll: true });
            selectedCard.current.scrollIntoView({ block: 'nearest' });
        }
    }, [selectedRequest]);
    return (
        <>
            <h2 className="text-section-title">Connected work</h2>
            <p className="text-subtle">
                Procedure review requests retain their named executor. The
                originating safety review and corrective action keep their own
                completion and verification checks.
            </p>
            {d.review_requests.length ? (
                <ul className="space-y-4">
                    {d.review_requests.map((item) => (
                        <li
                            key={item.id}
                            ref={
                                item.id === selectedRequest
                                    ? selectedCard
                                    : undefined
                            }
                            tabIndex={
                                item.id === selectedRequest ? -1 : undefined
                            }
                            aria-label={
                                item.id === selectedRequest
                                    ? 'Selected procedure review request'
                                    : undefined
                            }
                            className={`frontline-focus space-y-3 rounded-lg border p-4 ${item.id === selectedRequest ? 'border-primary' : 'border-border'}`}
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <h3 className="font-semibold">
                                    Procedure review request
                                </h3>
                                <StatusBadge status={item.status} />
                            </div>
                            <p className="text-sm whitespace-pre-wrap">
                                {item.note}
                            </p>
                            <p className="text-subtle">
                                Responsible person:{' '}
                                {item.assigned_to?.name ?? 'Unassigned'} ·
                                Requested by{' '}
                                {item.requested_by?.name ?? 'Not recorded'}
                                {item.requested_at
                                    ? ` · ${formatDateTimeLong(item.requested_at)}`
                                    : ''}
                            </p>
                            <Link
                                className="frontline-tap frontline-focus inline-flex text-primary underline"
                                href={item.source.href}
                            >
                                {item.source.reference_number ??
                                    'Open safety review'}
                            </Link>
                            {item.corrective_action && (
                                <Link
                                    className="frontline-tap frontline-focus flex text-primary underline"
                                    href={item.corrective_action.href}
                                >
                                    {item.corrective_action.reference_number ??
                                        item.corrective_action.title}
                                </Link>
                            )}
                            <div className="flex flex-wrap gap-3">
                                <Link
                                    className="frontline-tap frontline-focus inline-flex text-primary underline"
                                    href={item.requested_version.href}
                                >
                                    Requested version{' '}
                                    {item.requested_version.version}
                                </Link>
                                {item.result_version && (
                                    <Link
                                        className="frontline-tap frontline-focus inline-flex text-primary underline"
                                        href={item.result_version.href}
                                    >
                                        Resulting version{' '}
                                        {item.result_version.version}
                                    </Link>
                                )}
                            </div>
                            {item.outcome && (
                                <p className="text-sm">
                                    Outcome:{' '}
                                    {item.outcome === 'revised'
                                        ? 'Revised version published'
                                        : 'Reviewed without changes'}
                                </p>
                            )}
                            {item.decision_note && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {item.decision_note}
                                </p>
                            )}
                            {item.delivery && (
                                <ProcedureDeliveryNotice
                                    delivery={item.delivery}
                                />
                            )}
                            <ProcedureRequestActions
                                request={item}
                                detail={d}
                                requestDiscard={requestDiscard}
                            />
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">
                    No linked review requests are available here.
                </p>
            )}
        </>
    );
}

function ProcedureDeliveryNotice({
    delivery,
}: {
    delivery: NonNullable<
        ProcedureWorkspaceDetail['review_requests'][number]['delivery']
    >;
}) {
    const message =
        delivery.status === 'delivered'
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
    return <p className="text-subtle">Assignment notice: {message}</p>;
}
