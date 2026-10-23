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
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { InfoCard } from '@/components/wizard/primitives';
import {
    SafetyFormDraftContext,
    useSafetyDraftState,
    useSafetyForm,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
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
    FileText,
    Gauge,
    History,
    Info,
    Link2,
    Paperclip,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    RaCommand,
    RISK_COMMAND_LABELS,
    riskCommandAllowed,
} from './ra-command';
import {
    cap,
    CONSEQUENCE_LABELS,
    LevelCell,
    LIKELIHOOD_LABELS,
    ReviewBadge,
    statusMeta,
} from './ra-kit';
import { RaMatrix } from './ra-matrix';
import { RaWizardDialog } from './ra-wizard-dialog';
import type { RaConnectedWork, RaDetail, RaPickers } from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'risk', label: 'Risk & controls', icon: Gauge },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-subtle">{label}</dt>
            <dd className="text-sm break-words whitespace-pre-wrap">
                {children}
            </dd>
        </div>
    );
}

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
    const connections = d.connections;
    const nextReview = d.review_due_at
        ? formatDateOnly(d.review_due_at)
        : 'Not set';
    const nextCommand =
        d.status === 'draft'
            ? 'approve'
            : d.status === 'under_review'
              ? d.can.complete_review
                  ? 'complete-review'
                  : 'residual'
              : 'review';
    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        backHref={location.returnTo}
                        icon={ShieldAlert}
                        title={d.reference_number}
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
                        subline={`${d.title} · ${d.attached_to.name}`}
                        mobileSummary={`Review: ${nextReview} · ${d.risk_acceptable == null ? 'Residual acceptability not recorded' : d.risk_acceptable ? 'Residual risk acceptable' : 'Residual risk not acceptable'}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Inherent risk"
                                    onClick={() => move('risk')}
                                    tone={
                                        d.risk_level === 'extreme'
                                            ? 'critical'
                                            : d.risk_level === 'high'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {cap(d.risk_level)} · {d.risk_score}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Before controls
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Residual risk"
                                    tone={
                                        d.risk_acceptable === false
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    onClick={() => move('risk')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.residual_risk_level
                                            ? cap(d.residual_risk_level)
                                            : 'Not recorded'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {d.risk_acceptable == null
                                            ? 'Acceptability not recorded'
                                            : d.risk_acceptable
                                              ? 'Assessed as acceptable'
                                              : 'Assessed as not acceptable'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Review due"
                                    onClick={() => move('overview')}
                                    tone={
                                        d.review_state.kind === 'overdue'
                                            ? 'critical'
                                            : d.review_state.kind === 'today'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {nextReview}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {d.review_state.kind === 'overdue'
                                            ? 'Overdue'
                                            : d.review_state.kind === 'today'
                                              ? 'Due today'
                                              : status.label}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence"
                                    onClick={() => move('evidence')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.attachments.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Supporting files
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
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
                    <Card className="min-w-0">
                        <CardContent className="p-5">
                            <div
                                ref={panelRef}
                                tabIndex={-1}
                                className="min-w-0 space-y-5 focus:outline-none"
                                aria-label={
                                    SECTIONS.find((s) => s.key === section)
                                        ?.label
                                }
                            >
                                {command ? (
                                    riskCommandAllowed(d, command) ? (
                                        <RaCommand
                                            key={command}
                                            detail={d}
                                            command={command}
                                            onCancel={() => move(section)}
                                            onSaved={saved}
                                        />
                                    ) : (
                                        <>
                                            <InfoCard icon={Info}>
                                                This action is unavailable in
                                                the assessment’s current state
                                                or with your current access.
                                            </InfoCard>
                                            <Button
                                                className="frontline-tap"
                                                onClick={() => move(section)}
                                            >
                                                Back to assessment
                                            </Button>
                                        </>
                                    )
                                ) : (
                                    <>
                                        {section === 'overview' && (
                                            <>
                                                <h2 className="text-section-title">
                                                    {d.title}
                                                </h2>
                                                <dl className="grid gap-5 sm:grid-cols-2">
                                                    <Fact label="What this assessment covers">
                                                        {d.risk_description ||
                                                            'Not recorded'}
                                                    </Fact>
                                                    <Fact label="Applies to">
                                                        {d.attached_to.name}
                                                    </Fact>
                                                    <Fact label="Assessed by">
                                                        {d.assessed_by_name ??
                                                            'Not recorded'}
                                                        {d.assessed_at &&
                                                            ` · ${formatDateTime(d.assessed_at)}`}
                                                    </Fact>
                                                    <Fact label="Recorded approval">
                                                        {d.approved_by_name &&
                                                        d.approved_at
                                                            ? `${d.approved_by_name} · ${formatDateTime(d.approved_at)}`
                                                            : 'No recorded approval'}
                                                    </Fact>
                                                    <Fact label="Review due">
                                                        {nextReview}{' '}
                                                        <ReviewBadge
                                                            state={
                                                                d.review_state
                                                            }
                                                            dueAt={
                                                                d.review_due_at
                                                            }
                                                        />
                                                    </Fact>
                                                    <Fact label="Review frequency">
                                                        {d.review_frequency_days ==
                                                        null
                                                            ? 'Not set'
                                                            : `Every ${d.review_frequency_days} days`}
                                                    </Fact>
                                                    <Fact label="Approval note">
                                                        {d.approval_note ||
                                                            'Not recorded'}
                                                    </Fact>
                                                    <Fact label="Latest residual or review note">
                                                        {d.last_review_note ||
                                                            'Not recorded'}
                                                    </Fact>
                                                    <Fact label="Created">
                                                        {d.created_by_name ??
                                                            'Creator not recorded'}{' '}
                                                        ·{' '}
                                                        {formatDateTime(
                                                            d.created_at,
                                                        )}
                                                    </Fact>
                                                    <Fact label="Last updated">
                                                        {formatDateTime(
                                                            d.updated_at,
                                                        )}
                                                    </Fact>
                                                </dl>
                                                {connections?.versions.next && (
                                                    <InfoCard icon={Info}>
                                                        A newer assessment is
                                                        available.{' '}
                                                        <Link
                                                            className="frontline-tap frontline-focus inline-flex underline"
                                                            href={
                                                                connections
                                                                    .versions
                                                                    .next.href
                                                            }
                                                        >
                                                            Open{' '}
                                                            {
                                                                connections
                                                                    .versions
                                                                    .next
                                                                    .reference_number
                                                            }
                                                        </Link>
                                                    </InfoCard>
                                                )}
                                            </>
                                        )}
                                        {section === 'risk' && (
                                            <RiskControls detail={d} />
                                        )}
                                        {section === 'evidence' && (
                                            <RiskEvidence detail={d} />
                                        )}
                                        {section === 'history' && (
                                            <RiskHistory detail={d} />
                                        )}
                                        {section === 'connections' && (
                                            <RiskConnections detail={d} />
                                        )}
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <Card
                        className={
                            !command && commands.length
                                ? 'order-first xl:order-last'
                                : ''
                        }
                    >
                        <CardHeader>
                            <CardTitle>Next steps</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {commands.length ||
                            d.can.edit ||
                            d.can.supersede ? (
                                <div className="flex flex-col gap-2">
                                    {d.can.edit && (
                                        <Button
                                            className="frontline-tap h-auto justify-start whitespace-normal"
                                            variant="outline"
                                            onClick={() =>
                                                drafts.request(() =>
                                                    setWizard('edit'),
                                                )
                                            }
                                        >
                                            Edit draft
                                        </Button>
                                    )}
                                    {commands.map((c) => (
                                        <Button
                                            key={c}
                                            className="frontline-tap h-auto justify-start whitespace-normal"
                                            variant={
                                                c === nextCommand
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => move(section, c)}
                                        >
                                            {RISK_COMMAND_LABELS[c]}
                                        </Button>
                                    ))}
                                    {d.can.supersede && (
                                        <Button
                                            className="frontline-tap h-auto justify-start whitespace-normal"
                                            variant="outline"
                                            onClick={() =>
                                                drafts.request(() =>
                                                    setWizard('supersede'),
                                                )
                                            }
                                        >
                                            Create a new version
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <p className="text-subtle">
                                    No changes are available with your access
                                    and this assessment’s current state.
                                </p>
                            )}
                            {d.can.manage &&
                                d.status === 'under_review' &&
                                !d.can.complete_review && (
                                    <div className="space-y-2 text-sm">
                                        <p className="font-medium">
                                            Before completing review
                                        </p>
                                        <ul className="list-disc space-y-1 pl-5">
                                            {d.review_completion_gate?.reasons.map(
                                                (reason) => (
                                                    <li key={reason}>
                                                        {reason}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}
                            <p className="text-subtle">
                                Assessor and approver names record who made
                                those decisions. Owners and due dates for linked
                                safety reviews and actions appear under
                                Connected work.
                            </p>
                        </CardContent>
                    </Card>
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

function RiskControls({ detail: d }: { detail: RaDetail }) {
    return (
        <>
            <h2 className="text-section-title">Risk & controls</h2>
            <dl className="grid gap-5 sm:grid-cols-2">
                <Fact label="Inherent risk">
                    <LevelCell score={d.risk_score} level={d.risk_level} /> ·{' '}
                    {LIKELIHOOD_LABELS[d.likelihood - 1]} likelihood,{' '}
                    {CONSEQUENCE_LABELS[d.consequence - 1]} consequence
                </Fact>
                <Fact label="Residual risk">
                    {d.residual_risk_score != null && d.residual_risk_level ? (
                        <LevelCell
                            score={d.residual_risk_score}
                            level={d.residual_risk_level}
                        />
                    ) : (
                        'Not recorded'
                    )}
                </Fact>
                <Fact label="Existing controls">
                    {d.existing_controls || 'Not recorded'}
                </Fact>
                <Fact label="Additional controls">
                    {d.additional_controls || 'Not recorded'}
                </Fact>
                <Fact label="Residual risk acceptable">
                    {d.risk_acceptable == null
                        ? 'Not recorded'
                        : d.risk_acceptable
                          ? 'Yes'
                          : 'No'}
                </Fact>
            </dl>
            <div
                role="region"
                aria-label="Risk assessment matrix"
                tabIndex={0}
                className="frontline-focus overflow-x-auto rounded-lg p-2"
            >
                <RaMatrix
                    likelihood={d.likelihood}
                    consequence={d.consequence}
                    residualLikelihood={d.residual_likelihood}
                    residualConsequence={d.residual_consequence}
                />
            </div>
        </>
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
    return (
        <>
            <h2 className="text-section-title">Evidence</h2>
            {d.can.add_evidence && (
                <AttachmentUploader
                    endpoint={`${RISK_REGISTER}/${d.id}/attachments`}
                    noteField="notes"
                    onStateChange={setUploadState}
                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                    hint="Images, PDF, Word or Excel — up to 20 MB each"
                    categoryField={{
                        field: 'kind',
                        label: 'Evidence type',
                        options: [
                            { value: 'swms', label: 'SWMS' },
                            {
                                value: 'method_statement',
                                label: 'Method statement',
                            },
                            { value: 'photo', label: 'Photo' },
                            { value: 'sds', label: 'Safety data sheet' },
                            { value: 'plan', label: 'Site plan' },
                            { value: 'document', label: 'Other document' },
                        ],
                    }}
                />
            )}
            {attachment && (
                <div className="space-y-3 rounded-lg border border-border p-4">
                    <p>
                        Remove <strong>{attachment.original_name}</strong> from
                        this assessment?
                    </p>
                    <p className="text-subtle">
                        The removal is recorded in history.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            className="frontline-tap"
                            variant="destructive"
                            disabled={removal.processing}
                            onClick={() =>
                                removal.delete(
                                    `${RISK_REGISTER}/${d.id}/attachments/${attachment.id}`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setSelected(null),
                                    },
                                )
                            }
                        >
                            Remove evidence
                        </Button>
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            disabled={removal.processing}
                            onClick={() => setSelected(null)}
                        >
                            Keep evidence
                        </Button>
                    </div>
                </div>
            )}
            {Object.keys(removal.errors).length > 0 && (
                <p role="alert" className="text-status-critical">
                    {Object.values(removal.errors).join(' ')}
                </p>
            )}
            {d.attachments.length ? (
                <ul className="space-y-3">
                    {d.attachments.map((a) => (
                        <li
                            key={a.id}
                            className="space-y-2 rounded-lg border border-border p-3"
                        >
                            <a
                                href={a.download_url}
                                className="frontline-tap frontline-focus inline-flex break-all text-primary underline"
                            >
                                {a.original_name}
                            </a>
                            <p className="text-subtle">
                                {a.uploaded_by_name ?? 'Uploader not recorded'}{' '}
                                · {formatDateTime(a.created_at)}
                                {a.kind && ` · ${a.kind.replaceAll('_', ' ')}`}
                            </p>
                            {a.notes && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {a.notes}
                                </p>
                            )}
                            {d.can.remove_evidence && (
                                <Button
                                    className="frontline-tap"
                                    variant="outline"
                                    onClick={() => setSelected(a.id)}
                                >
                                    Remove {a.original_name}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">No evidence is recorded.</p>
            )}
        </>
    );
}

export function RiskHistory({ detail: d }: { detail: RaDetail }) {
    return (
        <>
            <h2 className="text-section-title">History</h2>
            {d.history?.length ? (
                <ol className="space-y-4">
                    {d.history.map((entry) => (
                        <li
                            key={entry.id}
                            className="space-y-2 rounded-lg border border-border p-4"
                        >
                            <h3 className="font-semibold">{entry.title}</h3>
                            <p className="text-subtle">
                                {entry.actor?.name ?? 'Actor not recorded'} ·{' '}
                                {formatDateTime(entry.occurred_at)}
                            </p>
                            {entry.note && (
                                <p className="text-sm break-words whitespace-pre-wrap">
                                    {entry.note}
                                </p>
                            )}
                            {entry.legacy && (
                                <p className="text-subtle">
                                    Older audit entry: the original record may
                                    contain fewer details.
                                </p>
                            )}
                            <details className="space-y-3">
                                <summary className="frontline-tap frontline-focus flex cursor-pointer items-center text-sm font-medium text-primary underline">
                                    View recorded assessment
                                </summary>
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {Object.entries(entry.snapshot)
                                        .filter(
                                            ([key]) =>
                                                ![
                                                    'approval_note',
                                                    'last_review_note',
                                                ].includes(key),
                                        )
                                        .map(([key, value]) => (
                                            <Fact
                                                key={key}
                                                label={cap(
                                                    key.replaceAll('_', ' '),
                                                )}
                                            >
                                                {value == null
                                                    ? 'Not recorded'
                                                    : typeof value === 'boolean'
                                                      ? value
                                                          ? 'Yes'
                                                          : 'No'
                                                      : Array.isArray(value)
                                                        ? value.join(', ')
                                                        : String(value)}
                                            </Fact>
                                        ))}
                                </dl>
                            </details>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-subtle">
                    No recorded history is available here. Assessor and approval
                    details remain in Overview.
                </p>
            )}
        </>
    );
}

function RiskConnections({ detail: d }: { detail: RaDetail }) {
    const c = d.connections;
    const work = (items: RaConnectedWork[]) =>
        items.length ? (
            <ul className="space-y-3">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className="space-y-2 rounded-lg border border-border p-3"
                    >
                        <Link
                            href={item.href}
                            className="frontline-tap frontline-focus inline-flex break-words text-primary underline"
                        >
                            {item.reference_number
                                ? `${item.reference_number} · `
                                : ''}
                            {item.title}
                        </Link>
                        <p className="text-sm">
                            {cap(
                                (item.display_state ?? item.status).replaceAll(
                                    '_',
                                    ' ',
                                ),
                            )}{' '}
                            · Owner: {item.assigned_to?.name ?? 'Unassigned'}
                        </p>
                        <p className="text-subtle">
                            Due: {formatDateOnly(item.due_date, 'Not set')}
                        </p>
                    </li>
                ))}
            </ul>
        ) : (
            <p className="text-subtle">No linked records available here.</p>
        );
    return (
        <>
            <h2 className="text-section-title">Connected work</h2>
            <p className="text-subtle">
                Each linked record keeps its own owner, permissions and
                completion checks.
            </p>
            {c?.source && (
                <section>
                    <h3 className="font-semibold">Applies to</h3>
                    <Link
                        href={c.source.href}
                        className="frontline-tap frontline-focus inline-flex text-primary underline"
                    >
                        {c.source.title}
                    </Link>
                </section>
            )}
            <section className="space-y-3">
                <h3 className="font-semibold">Safety review</h3>
                {c?.safety_review ? (
                    <div>
                        <Link
                            className="frontline-tap frontline-focus inline-flex text-primary underline"
                            href={c.safety_review.href}
                        >
                            {c.safety_review.reference_number}
                        </Link>
                        <p className="text-sm">
                            Review owner:{' '}
                            {c.safety_review.owner?.name ?? 'Unassigned'} ·{' '}
                            {cap(c.safety_review.status.replaceAll('_', ' '))}
                        </p>
                    </div>
                ) : (
                    <p className="text-subtle">
                        No linked review available here.
                    </p>
                )}
            </section>
            <section className="space-y-3">
                <h3 className="font-semibold">Corrective actions</h3>
                {work(c?.corrective_actions ?? [])}
            </section>
            <section className="space-y-3">
                <h3 className="font-semibold">Tasks</h3>
                {work(c?.tasks ?? [])}
                {c?.tasks_href && (
                    <Link
                        href={c.tasks_href}
                        className="frontline-tap frontline-focus inline-flex text-primary underline"
                    >
                        Open Tasks
                    </Link>
                )}
            </section>
            <section className="space-y-3">
                <h3 className="font-semibold">Assessment versions</h3>
                {c && (c.versions.next || c.versions.previous.length) ? (
                    <ul className="space-y-2">
                        {[
                            ...c.versions.previous,
                            ...(c.versions.next ? [c.versions.next] : []),
                        ].map((v) => (
                            <li key={v.id}>
                                <Link
                                    href={v.href}
                                    className="frontline-tap frontline-focus inline-flex text-primary underline"
                                >
                                    {v.reference_number} ·{' '}
                                    {statusMeta(v.status).label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-subtle">
                        No other versions available here.
                    </p>
                )}
            </section>
        </>
    );
}
