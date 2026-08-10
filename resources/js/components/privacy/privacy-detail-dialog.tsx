/* eslint-disable no-restricted-syntax -- Detail-as-modal on the shared
 * WizardShell chrome (sections rail + ReviewCards + footer status chips +
 * Options bar). Semantic tokens only. */
import {
    PrivacyActionModal,
    type PrivacyActionKind,
} from '@/components/privacy/privacy-action-modal';
import {
    PrivacyAttachmentsPane,
    type PrivacyAttachableType,
    type PrivacyAttachmentItem,
} from '@/components/privacy/privacy-attachments-pane';
import { Button } from '@/components/ui/button';
import { type IconType } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    breachStatus,
    dpiaOutcome,
    fmtDate,
    fmtDateTime,
    holdStatus,
    PRIVACY_PILL,
    requestStatus,
    requestType,
    riskLevel,
    titleCase,
    type PrivacyTone,
} from '@/pages/privacy/privacy-shared';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Check,
    Clock,
    Download,
    ExternalLink,
    FileText,
    Fingerprint,
    Gauge,
    History,
    ListChecks,
    Mail,
    Paperclip,
    Scale,
    ShieldAlert,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

/* eslint-disable @typescript-eslint/no-explicit-any -- detail is a server-shaped
 * union keyed by `kind`; per-kind access is guarded by the kind switch. */
export type PrivacyDetail = Record<string, any> & {
    kind: 'request' | 'breach' | 'hold' | 'dpia';
    id: number;
    reference: string;
    attachments: PrivacyAttachmentItem[];
    timeline: { at: string; label: string; tone: PrivacyTone }[];
};

export type PrivacyCan = {
    processRequests: boolean;
    reportBreaches: boolean;
    manageLegalHolds: boolean;
    conductDPIA: boolean;
};

type Section = {
    key: string;
    label: string;
    blurb: string;
    icon: IconType;
    render: () => ReactNode;
};
type OptionBtn = {
    key: PrivacyActionKind;
    label: string;
    icon: IconType;
    tone?: 'primary' | 'critical';
    show: boolean;
};

export function PrivacyDetailDialog({
    detail,
    can,
    open,
    onClose,
    initialAction = null,
}: {
    detail: PrivacyDetail;
    can: PrivacyCan;
    open: boolean;
    onClose: () => void;
    initialAction?: PrivacyActionKind | null;
}) {
    const [section, setSection] = useState(0);
    const [action, setAction] = useState<PrivacyActionKind | null>(null);

    // Deep-link: a ctx-menu item can open straight onto an action.
    useEffect(() => {
        if (initialAction) {
            const t = setTimeout(() => setAction(initialAction), 60);
            return () => clearTimeout(t);
        }
    }, [initialAction]);

    const canManage =
        detail.kind === 'request'
            ? can.processRequests
            : detail.kind === 'breach'
              ? can.reportBreaches
              : detail.kind === 'hold'
                ? can.manageLegalHolds
                : can.conductDPIA;

    const attachableType: PrivacyAttachableType = detail.kind;

    const docs: Section = {
        key: 'documents',
        label: 'Documents',
        blurb: `${detail.attachments.length || 'No'} attached`,
        icon: Paperclip,
        render: () => (
            <PrivacyAttachmentsPane
                attachableType={attachableType}
                attachableId={detail.id}
                attachments={detail.attachments}
                canManage={canManage}
            />
        ),
    };
    const history: Section = {
        key: 'history',
        label: 'History',
        blurb: 'Audit trail',
        icon: History,
        render: () => <Timeline events={detail.timeline} />,
    };

    const { railIcon, railTitle, railSub, sections, chips, options } =
        buildKind(detail, canManage);
    const allSections = [...sections, docs, history];
    const steps: WizardStep[] = allSections.map((s) => ({
        key: s.key,
        label: s.label,
        blurb: s.blurb,
        icon: s.icon,
    }));
    const shown = options.filter((o) => o.show);

    return (
        <>
            <WizardShell
                open={open}
                onClose={onClose}
                title={railTitle}
                description={`${railSub} — record detail`}
                railIcon={railIcon}
                railTitle={railTitle}
                railSub={railSub}
                steps={steps}
                stepIndex={section}
                onStepClick={setSection}
                pct={null}
                footerStart={
                    <div className="flex flex-wrap items-center gap-1.5">
                        {chips}
                    </div>
                }
                footerEnd={
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => router.visit(openFullUrl(detail))}
                        >
                            <ExternalLink className="h-4 w-4" /> Open full page
                        </Button>
                        {shown.map((o) => (
                            <Button
                                key={o.key}
                                type="button"
                                size="sm"
                                variant={
                                    o.tone === 'critical'
                                        ? 'destructive'
                                        : o.tone === 'primary'
                                          ? 'default'
                                          : 'outline'
                                }
                                onClick={() => setAction(o.key)}
                            >
                                <o.icon className="h-4 w-4" /> {o.label}
                            </Button>
                        ))}
                    </div>
                }
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    {allSections[section]?.render()}
                </div>
            </WizardShell>

            {action ? (
                <PrivacyActionModal
                    kind={action}
                    recordId={detail.id}
                    open
                    onClose={() => setAction(null)}
                />
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Per-kind sections, chips, options                                 */
/* ------------------------------------------------------------------ */

function buildKind(
    d: PrivacyDetail,
    canManage: boolean,
): {
    railIcon: IconType;
    railTitle: string;
    railSub: string;
    sections: Section[];
    chips: ReactNode;
    options: OptionBtn[];
} {
    if (d.kind === 'request') {
        const st = requestStatus(d.status);
        const ty = requestType(d.request_type);
        const open = !['completed', 'rejected', 'withdrawn'].includes(d.status);
        const verified = d.identity_verified === 'verified';
        return {
            railIcon: FileText,
            railTitle: d.reference,
            railSub: ty.label,
            chips: (
                <>
                    <Chip tone={st.tone}>{st.label}</Chip>
                    <Chip tone={ty.tone}>{ty.label}</Chip>
                    {d.is_overdue ? (
                        <Chip tone="critical">Overdue · 20 wd</Chip>
                    ) : null}
                </>
            ),
            options: [
                {
                    key: 'verify',
                    label: 'Verify identity',
                    icon: Fingerprint,
                    tone: 'primary',
                    show: canManage && open && !verified,
                },
                {
                    key: 'extend',
                    label: 'Extend',
                    icon: Clock,
                    show: canManage && open,
                },
                {
                    key: 'complete',
                    label: 'Complete',
                    icon: Check,
                    show: canManage && open,
                },
                {
                    key: 'refuse',
                    label: 'Refuse',
                    icon: Ban,
                    tone: 'critical',
                    show: canManage && open,
                },
                {
                    key: 'export',
                    label: 'Export package',
                    icon: Download,
                    show: canManage,
                },
            ],
            sections: [
                {
                    key: 'overview',
                    label: 'Overview',
                    blurb: 'Request & origin',
                    icon: FileText,
                    render: () => (
                        <>
                            <ReviewCard icon={FileText} title="Request">
                                <ReviewRow
                                    label="Reference"
                                    value={d.reference}
                                />
                                <ReviewRow label="Type" value={ty.label} />
                                <ReviewRow
                                    label="Received"
                                    value={fmtDate(d.received_at)}
                                />
                                <ReviewRow
                                    label="Assigned to"
                                    value={d.assigned_to}
                                />
                            </ReviewCard>
                            <ReviewCard icon={Users} title="Subject">
                                <ReviewRow
                                    label="Name"
                                    value={d.subject_name}
                                />
                                <ReviewRow
                                    label="Email"
                                    value={d.subject_email}
                                />
                                <ReviewRow
                                    label="Linked client"
                                    value={d.client?.name}
                                />
                            </ReviewCard>
                            <Prose
                                title="Request details"
                                icon={ListChecks}
                                text={d.request_details}
                            />
                        </>
                    ),
                },
                {
                    key: 'subject',
                    label: 'Subject & verification',
                    blurb: verified ? 'Verified' : 'Pending',
                    icon: Fingerprint,
                    render: () => (
                        <ReviewCard
                            icon={Fingerprint}
                            title="Identity verification"
                            span
                        >
                            <ReviewRow
                                label="Status"
                                value={
                                    <Chip
                                        tone={verified ? 'success' : 'warning'}
                                    >
                                        {verified ? 'Verified' : 'Pending'}
                                    </Chip>
                                }
                            />
                            <ReviewRow
                                label="Method"
                                value={d.verification_method}
                            />
                            <ReviewRow
                                label="Verified by"
                                value={d.verified_by}
                            />
                            <ReviewRow
                                label="When"
                                value={fmtDateTime(d.identity_verified_at)}
                            />
                            <ReviewRow
                                label="Basis"
                                value="IPP 6 — confirm identity before release"
                            />
                        </ReviewCard>
                    ),
                },
                {
                    key: 'timeline',
                    label: 'Timeline & deadline',
                    blurb: d.is_overdue ? 'Overdue' : 'On track',
                    icon: Clock,
                    render: () => (
                        <>
                            <ReviewCard
                                icon={Clock}
                                title="Statutory deadline"
                                span
                            >
                                <ReviewRow
                                    label="Received"
                                    value={fmtDate(d.received_at)}
                                />
                                <ReviewRow
                                    label="Due date"
                                    value={
                                        <span
                                            className={cn(
                                                d.is_overdue &&
                                                    'font-bold text-status-critical',
                                            )}
                                        >
                                            {fmtDate(d.deadline)}
                                        </span>
                                    }
                                />
                                {d.extended_due_date ? (
                                    <ReviewRow
                                        label="Extended to"
                                        value={fmtDate(d.extended_due_date)}
                                    />
                                ) : null}
                                <ReviewRow
                                    label="Basis"
                                    value="IPP 6 · 20 working days"
                                />
                                <ReviewRow
                                    label="Days remaining"
                                    value={
                                        d.is_overdue
                                            ? 'Overdue'
                                            : `${d.days_remaining} days`
                                    }
                                />
                            </ReviewCard>
                            {d.status === 'completed' ? (
                                <Prose
                                    title="Completion notes"
                                    icon={Check}
                                    text={d.completion_notes}
                                />
                            ) : null}
                            {d.status === 'rejected' ? (
                                <>
                                    <Prose
                                        title="Refusal reason"
                                        icon={Ban}
                                        text={d.rejection_reason}
                                    />
                                    <Prose
                                        title="Legal basis"
                                        icon={Scale}
                                        text={d.rejection_legal_basis}
                                    />
                                </>
                            ) : null}
                        </>
                    ),
                },
            ],
        };
    }

    if (d.kind === 'breach') {
        const st = breachStatus(d.status);
        const open = d.status !== 'resolved';
        return {
            railIcon: AlertTriangle,
            railTitle: d.reference,
            railSub: st.label,
            chips: (
                <>
                    <Chip tone={st.tone}>{st.label}</Chip>
                    {d.severity ? (
                        <Chip tone="neutral">{titleCase(d.severity)}</Chip>
                    ) : null}
                    {d.opc_required && !d.opc_notified_at ? (
                        <Chip tone="critical">OPC due</Chip>
                    ) : null}
                    {d.subject_required && !d.subject_notified_at ? (
                        <Chip tone="warning">Subjects due</Chip>
                    ) : null}
                </>
            ),
            options: [
                {
                    key: 'notify-opc',
                    label: 'Notify OPC',
                    icon: ShieldAlert,
                    tone: 'critical',
                    show: canManage && d.opc_required && !d.opc_notified_at,
                },
                {
                    key: 'notify-subjects',
                    label: 'Notify subjects',
                    icon: Mail,
                    show:
                        canManage &&
                        d.subject_required &&
                        !d.subject_notified_at,
                },
                {
                    key: 'resolve',
                    label: 'Resolve',
                    icon: Check,
                    tone: 'primary',
                    show: canManage && open,
                },
            ],
            sections: [
                {
                    key: 'overview',
                    label: 'Overview',
                    blurb: 'Breach & impact',
                    icon: AlertTriangle,
                    render: () => (
                        <>
                            <ReviewCard icon={AlertTriangle} title="Breach">
                                <ReviewRow
                                    label="Reference"
                                    value={d.reference}
                                />
                                <ReviewRow
                                    label="Status"
                                    value={
                                        <Chip tone={st.tone}>{st.label}</Chip>
                                    }
                                />
                                <ReviewRow
                                    label="Severity"
                                    value={
                                        d.severity
                                            ? titleCase(d.severity)
                                            : null
                                    }
                                />
                                <ReviewRow
                                    label="Discovered"
                                    value={fmtDate(d.discovered_at)}
                                />
                                <ReviewRow
                                    label="Discovered by"
                                    value={d.discovered_by}
                                />
                            </ReviewCard>
                            <ReviewCard icon={Users} title="Impact">
                                <ReviewRow
                                    label="Individuals affected"
                                    value={d.approximate_individuals_affected}
                                />
                                <ReviewRow
                                    label="Data categories"
                                    value={joinArr(d.affected_data_categories)}
                                />
                            </ReviewCard>
                            <Prose
                                title="Nature of breach"
                                icon={FileText}
                                text={d.nature_of_breach}
                            />
                        </>
                    ),
                },
                {
                    key: 'assessment',
                    label: 'Assessment',
                    blurb: 'Harm & response',
                    icon: ShieldCheck,
                    render: () => (
                        <>
                            <Prose
                                title="Likely consequences"
                                icon={AlertTriangle}
                                text={d.likely_consequences}
                            />
                            <Prose
                                title="Measures taken"
                                icon={ShieldCheck}
                                text={d.measures_taken}
                            />
                            {d.resolution_notes ? (
                                <Prose
                                    title="Resolution notes"
                                    icon={Check}
                                    text={d.resolution_notes}
                                />
                            ) : null}
                        </>
                    ),
                },
                {
                    key: 'notification',
                    label: 'Notification',
                    blurb: 'OPC & subjects',
                    icon: Mail,
                    render: () => (
                        <>
                            <ReviewCard
                                icon={ShieldAlert}
                                title="Privacy Commissioner (OPC)"
                            >
                                <ReviewRow
                                    label="Required"
                                    value={
                                        d.opc_required
                                            ? 'Yes — as soon as practicable'
                                            : 'No'
                                    }
                                />
                                <ReviewRow
                                    label="Notified"
                                    value={fmtDate(d.opc_notified_at)}
                                />
                                <ReviewRow
                                    label="Reference"
                                    value={d.authority_reference}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Mail}
                                title="Affected individuals"
                            >
                                <ReviewRow
                                    label="Required"
                                    value={d.subject_required ? 'Yes' : 'No'}
                                />
                                <ReviewRow
                                    label="Notified"
                                    value={fmtDate(d.subject_notified_at)}
                                />
                                <ReviewRow
                                    label="Method"
                                    value={d.notification_method}
                                />
                            </ReviewCard>
                        </>
                    ),
                },
            ],
        };
    }

    if (d.kind === 'hold') {
        const st = holdStatus(d.status);
        return {
            railIcon: Scale,
            railTitle: d.reference,
            railSub: titleCase(d.hold_type ?? 'Legal hold'),
            chips: (
                <>
                    <Chip tone={st.tone}>{st.label}</Chip>
                    <Chip tone="info">{titleCase(d.hold_type ?? '')}</Chip>
                </>
            ),
            options: [
                {
                    key: 'release',
                    label: 'Release hold',
                    icon: Ban,
                    tone: 'critical',
                    show: canManage && d.status === 'active',
                },
            ],
            sections: [
                {
                    key: 'overview',
                    label: 'Overview',
                    blurb: 'Hold & authority',
                    icon: Scale,
                    render: () => (
                        <>
                            <ReviewCard icon={Scale} title="Legal hold">
                                <ReviewRow
                                    label="Reference"
                                    value={d.reference}
                                />
                                <ReviewRow
                                    label="Type"
                                    value={titleCase(d.hold_type ?? '')}
                                />
                                <ReviewRow
                                    label="Status"
                                    value={
                                        <Chip tone={st.tone}>{st.label}</Chip>
                                    }
                                />
                                <ReviewRow
                                    label="Imposed"
                                    value={fmtDate(d.imposed_at)}
                                />
                                <ReviewRow
                                    label="Imposed by"
                                    value={d.imposed_by}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Authority & review"
                            >
                                <ReviewRow
                                    label="Legal authority"
                                    value={d.legal_authority}
                                />
                                <ReviewRow
                                    label="Review date"
                                    value={fmtDate(d.review_date)}
                                />
                                {d.status === 'released' ? (
                                    <ReviewRow
                                        label="Released"
                                        value={fmtDate(d.released_at)}
                                    />
                                ) : null}
                                {d.status === 'released' ? (
                                    <ReviewRow
                                        label="Released by"
                                        value={d.released_by}
                                    />
                                ) : null}
                            </ReviewCard>
                            <Prose
                                title="Reason"
                                icon={ListChecks}
                                text={d.reason}
                            />
                            {d.release_reason ? (
                                <Prose
                                    title="Release reason"
                                    icon={Ban}
                                    text={d.release_reason}
                                />
                            ) : null}
                        </>
                    ),
                },
            ],
        };
    }

    // dpia
    const oc = dpiaOutcome(d.outcome);
    const risk = riskLevel(d.overall_risk_level);
    const inReview = !d.outcome;
    return {
        railIcon: ShieldCheck,
        railTitle: d.reference,
        railSub: d.assessment_name,
        chips: (
            <>
                <Chip tone={oc.tone}>{oc.label}</Chip>
                <Chip tone={risk.tone}>{risk.label} risk</Chip>
            </>
        ),
        options: [
            {
                key: 'approve',
                label: 'Approve',
                icon: Check,
                tone: 'primary',
                show: canManage && inReview,
            },
            {
                key: 'review',
                label: 'Send for review',
                icon: ShieldAlert,
                show: canManage && inReview,
            },
        ],
        sections: [
            {
                key: 'overview',
                label: 'Overview',
                blurb: 'Assessment & outcome',
                icon: ShieldCheck,
                render: () => (
                    <>
                        <ReviewCard icon={ShieldCheck} title="Assessment">
                            <ReviewRow label="Reference" value={d.reference} />
                            <ReviewRow label="Name" value={d.assessment_name} />
                            <ReviewRow
                                label="Project / process"
                                value={d.project_or_process}
                            />
                            <ReviewRow
                                label="Type"
                                value={titleCase(d.assessment_type ?? '')}
                            />
                            <ReviewRow label="Assessor" value={d.assessor} />
                            <ReviewRow
                                label="Date"
                                value={fmtDate(d.assessment_date)}
                            />
                        </ReviewCard>
                        <ReviewCard icon={Check} title="Outcome">
                            <ReviewRow
                                label="Outcome"
                                value={<Chip tone={oc.tone}>{oc.label}</Chip>}
                            />
                            <ReviewRow
                                label="Approved by"
                                value={d.approved_by}
                            />
                            <ReviewRow
                                label="Review date"
                                value={fmtDate(d.review_date)}
                            />
                        </ReviewCard>
                        {d.description ? (
                            <Prose
                                title="Description"
                                icon={FileText}
                                text={d.description}
                            />
                        ) : null}
                    </>
                ),
            },
            {
                key: 'processing',
                label: 'Processing',
                blurb: 'Purpose & data',
                icon: ListChecks,
                render: () => (
                    <>
                        <Prose
                            title="Processing purpose"
                            icon={ListChecks}
                            text={d.processing_purpose}
                        />
                        <Prose
                            title="Legal basis"
                            icon={Scale}
                            text={d.legal_basis}
                        />
                        <ReviewCard icon={Users} title="Scope">
                            <ReviewRow
                                label="Personal data types"
                                value={joinArr(d.personal_data_types)}
                            />
                            <ReviewRow
                                label="Who is affected"
                                value={joinArr(d.data_subjects)}
                            />
                        </ReviewCard>
                    </>
                ),
            },
            {
                key: 'risk',
                label: 'Risk',
                blurb: `${risk.label} risk`,
                icon: Gauge,
                render: () => (
                    <>
                        <ReviewCard icon={Gauge} title="Risk rating" span>
                            <ReviewRow
                                label="Overall risk"
                                value={
                                    <Chip tone={risk.tone}>{risk.label}</Chip>
                                }
                            />
                            <ReviewRow
                                label="Residual risk"
                                value={
                                    d.residual_risk_level
                                        ? riskLevel(d.residual_risk_level).label
                                        : null
                                }
                            />
                        </ReviewCard>
                        <Prose
                            title="Identified risks"
                            icon={AlertTriangle}
                            text={joinList(d.identified_risks)}
                        />
                        <Prose
                            title="Mitigation measures"
                            icon={ShieldCheck}
                            text={joinList(d.mitigation_measures)}
                        />
                        {d.review_notes ? (
                            <Prose
                                title="Review notes"
                                icon={History}
                                text={d.review_notes}
                            />
                        ) : null}
                    </>
                ),
            },
        ],
    };
}

/* ------------------------------------------------------------------ */
/*  Bits                                                               */
/* ------------------------------------------------------------------ */

function Chip({ tone, children }: { tone: PrivacyTone; children: ReactNode }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                PRIVACY_PILL[tone],
            )}
        >
            {children}
        </span>
    );
}

function Prose({
    title,
    icon: Icon,
    text,
}: {
    title: string;
    icon: IconType;
    text?: string | null;
}) {
    return (
        <ReviewCard icon={Icon} title={title} span>
            {text ? (
                <p className="text-[13px] leading-relaxed whitespace-pre-line text-foreground">
                    {text}
                </p>
            ) : (
                <p className="text-[13px] text-muted-foreground">—</p>
            )}
        </ReviewCard>
    );
}

function Timeline({
    events,
}: {
    events: { at: string; label: string; tone: PrivacyTone }[];
}) {
    if (!events.length)
        return (
            <div className="rounded-xl border border-dashed border-border p-4 text-center text-[13px] text-muted-foreground sm:col-span-2">
                No history yet.
            </div>
        );
    return (
        <ol className="flex flex-col gap-0 sm:col-span-2">
            {events.map((e, i) => (
                <li key={i} className="flex gap-3 pb-4 last:pb-0">
                    <div className="flex flex-col items-center">
                        <span
                            className={cn(
                                'mt-1 h-2.5 w-2.5 shrink-0 rounded-full',
                                dotFor(e.tone),
                            )}
                        />
                        {i < events.length - 1 ? (
                            <span className="w-px flex-1 bg-border" />
                        ) : null}
                    </div>
                    <div className="-mt-0.5">
                        <div className="text-[13px] font-semibold text-foreground">
                            {e.label}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {fmtDateTime(e.at)}
                        </div>
                    </div>
                </li>
            ))}
        </ol>
    );
}

const DOT: Record<PrivacyTone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-status-info',
    neutral: 'bg-muted-foreground',
};
const dotFor = (t: PrivacyTone) => DOT[t];

const joinArr = (a?: string[]): string | null =>
    Array.isArray(a) && a.length ? a.join(', ') : null;
const joinList = (a?: string[] | string): string | null =>
    Array.isArray(a)
        ? a.length
            ? a.map((x) => `• ${x}`).join('\n')
            : null
        : (a ?? null) || null;

function openFullUrl(d: PrivacyDetail): string {
    switch (d.kind) {
        case 'request':
            return `/privacy/requests/${d.id}`;
        case 'breach':
            return `/privacy/breaches/${d.id}`;
        case 'dpia':
            return `/privacy/pia/${d.id}`;
        default:
            return `/privacy/legal-holds/${d.id}/edit`;
    }
}
