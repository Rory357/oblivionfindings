/* eslint-disable no-restricted-syntax -- bespoke detail-modal surfaces (stat tiles,
 * evidence cards, dashed add-buttons, chips) are intentional custom layouts on
 * semantic tokens, mirroring drill-detail-dialog.tsx + the wizard primitives. */
/* Restraint event detail — the over-the-list governance modal. Built on WizardShell
 * (read-only chrome: section rail = "steps", Step X of Y header, footer Options bar)
 * exactly like drill-detail-dialog.tsx. The Review workflow replaces the body as a
 * pane (Add-Client idiom). Premium evidence upload (body maps, injury photos,
 * authorisation forms) lives in the Evidence section. NZ-only / least-restrictive. */
import { Button } from '@/components/ui/button';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    ATTACHMENT_CATEGORY_LABEL,
    ATTACHMENT_CATEGORY_OPTIONS,
    CHIP,
    DOT,
    durationLabel,
    fmtDateTime,
    formatFileSize,
    SEVERITY_OPTIONS,
    severityMeta,
    titleCase,
    typeMeta,
    type EventDetail,
    type IncidentOption,
} from '@/pages/health-safety/restraints/shared';
import type { Page } from '@inertiajs/core';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BookOpen,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Download,
    ExternalLink,
    HeartPulse,
    Link2,
    Paperclip,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    User,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type FormEvent } from 'react';

export type RestraintSectionKey =
    | 'overview'
    | 'response'
    | 'injury'
    | 'evidence'
    | 'review';
export type RestraintEventActionKey = 'review';

export function RestraintEventDetailDialog({
    detail,
    open,
    onClose,
    incidents = [],
    initialSection = 'overview',
    initialAction = null,
    onOpenPlan,
}: {
    detail: EventDetail;
    open: boolean;
    onClose: () => void;
    incidents?: IncidentOption[];
    initialSection?: RestraintSectionKey;
    initialAction?: RestraintEventActionKey | null;
    onOpenPlan?: (id: number) => void;
}) {
    const d = detail;
    const [section, setSection] = useState<RestraintSectionKey>(initialSection);
    const [reviewing, setReviewing] = useState(initialAction === 'review');
    const [linking, setLinking] = useState(false);

    useEffect(() => {
        setSection(initialSection);
        setReviewing(initialAction === 'review');
        setLinking(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes
    }, [initialSection, initialAction, d.id]);

    const type = typeMeta(d.restraint_type);
    const sev = severityMeta(d.severity);
    const reviewed = !!d.reviewed_at;

    // Rail design (consistent with drill-detail-dialog.tsx, the gold-standard sibling):
    // the rail lists exactly these five *read-only* governance views, and each blurb is
    // live-computed from the record (file count, injury flag, reviewed state) so the rail
    // doubles as an at-a-glance status. Workflows that *change* the record — Review,
    // Link incident — deliberately replace the body as a pane (the Add-Client idiom) and
    // are launched from the footer / a section button rather than added as rail entries:
    // they are actions, not sections. This keeps the rail stable and avoids the read/write
    // mixing that the Review section (status) vs. Review pane (edit form) split exists to
    // prevent. Accept-as-built — do not collapse the section/pane pairs into one.
    const SECTIONS: {
        key: RestraintSectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'The episode & links',
            icon: ShieldAlert,
        },
        {
            key: 'response',
            label: 'Response',
            blurb: 'Trigger, de-escalation',
            icon: Activity,
        },
        {
            key: 'injury',
            label: 'Injury & aftercare',
            blurb: d.injury_occurred ? 'Injury recorded' : 'No injury',
            icon: HeartPulse,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
            icon: Paperclip,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: reviewed ? 'Reviewed' : 'Not reviewed',
            icon: ClipboardCheck,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[type.tone]}`}
            >
                <span
                    className={`h-1.5 w-1.5 rounded-full ${DOT[type.tone]}`}
                />{' '}
                {type.label}
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[sev.tone]}`}
            >
                {sev.label}
            </span>
            {d.within_support_plan ? (
                <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP.success}`}
                >
                    <ShieldCheck className="h-3 w-3" /> Within plan
                </span>
            ) : (
                <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP.critical}`}
                >
                    <AlertTriangle className="h-3 w-3" /> Out of plan
                </span>
            )}
            {d.injury_occurred ? (
                <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP.critical}`}
                >
                    <HeartPulse className="h-3 w-3" /> Injury
                </span>
            ) : null}
        </div>
    );

    const footerEnd =
        reviewing || linking ? null : (
            <div className="flex flex-wrap items-center gap-2">
                {d.client ? (
                    <Link
                        href={`/operations/clients/${d.client.id}`}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
                    >
                        <ExternalLink className="h-4 w-4" /> Client profile
                    </Link>
                ) : null}
                {d.can.review ? (
                    <Button
                        size="sm"
                        onClick={() => setReviewing(true)}
                        variant={reviewed ? 'outline' : 'default'}
                    >
                        <ClipboardCheck className="mr-1.5 h-4 w-4" />{' '}
                        {reviewed ? 'Update review' : 'Review event'}
                    </Button>
                ) : null}
            </div>
        );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Restraint event ${d.reference}`}
            description={`${type.label} — ${sev.label}`}
            railIcon={type.icon}
            railTitle={d.reference}
            railSub={`${type.label} · ${d.client?.name ?? 'Unknown client'}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {reviewing ? (
                <ReviewPane d={d} onDone={() => setReviewing(false)} />
            ) : linking ? (
                <LinkIncidentPane
                    d={d}
                    incidents={incidents}
                    onDone={() => setLinking(false)}
                />
            ) : (
                <>
                    {section === 'overview' ? (
                        <OverviewSection
                            d={d}
                            onOpenPlan={onOpenPlan}
                            canReview={d.can.review}
                            onLink={() => setLinking(true)}
                        />
                    ) : null}
                    {section === 'response' ? <ResponseSection d={d} /> : null}
                    {section === 'injury' ? <InjurySection d={d} /> : null}
                    {section === 'evidence' ? <EvidenceSection d={d} /> : null}
                    {section === 'review' ? (
                        <ReviewSection
                            d={d}
                            onReview={() => setReviewing(true)}
                        />
                    ) : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({
    d,
    onOpenPlan,
    canReview,
    onLink,
}: {
    d: EventDetail;
    onOpenPlan?: (id: number) => void;
    canReview: boolean;
    onLink: () => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        The episode
                    </div>
                    <ReviewRow label="Client" value={d.client?.name} />
                    <ReviewRow label="Site" value={d.site?.name} />
                    <ReviewRow
                        label="Type"
                        value={titleCase(d.restraint_type)}
                    />
                    <ReviewRow
                        label="Severity"
                        value={severityMeta(d.severity).label}
                    />
                    <ReviewRow
                        label="Started"
                        value={fmtDateTime(d.started_at)}
                    />
                    <ReviewRow label="Ended" value={fmtDateTime(d.ended_at)} />
                    <ReviewRow
                        label="Duration"
                        value={durationLabel(d.duration_minutes)}
                    />
                </div>
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        Plan & authorisation
                    </div>
                    <ReviewRow
                        label="Within plan"
                        value={d.within_support_plan ? 'Yes' : 'No — deviation'}
                    />
                    {!d.within_support_plan ? (
                        <ReviewRow
                            label="Deviation reason"
                            value={d.deviation_reason}
                        />
                    ) : null}
                    <ReviewRow
                        label="Authorised by"
                        value={d.authorised_by?.name}
                    />
                    <ReviewRow
                        label="Staff involved"
                        value={
                            d.staff_involved.length
                                ? `${d.staff_involved.length}`
                                : undefined
                        }
                    />
                </div>
            </div>

            {d.plan ? (
                <button
                    type="button"
                    onClick={() => onOpenPlan?.(d.plan!.id)}
                    className="flex w-full items-center gap-3 rounded-xl border border-border bg-card/70 p-3 text-left transition-colors hover:border-primary/40 hover:bg-card"
                >
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BookOpen className="h-4 w-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold">
                            {d.plan.title}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {d.plan.reference} · {titleCase(d.plan.status)} —
                            open the behaviour support plan
                        </div>
                    </div>
                    <ExternalLink className="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>
            ) : (
                <InfoCard icon={AlertTriangle} tone="warn">
                    No behaviour support plan is linked to this event.
                    Restrictive practice should be governed by a current plan.
                </InfoCard>
            )}

            {d.related_incident ? (
                <div className="flex flex-col gap-2">
                    <Link
                        href={`/incidents?incident=${d.related_incident.id}`}
                        className="block"
                    >
                        <div className="flex w-full items-center gap-3 rounded-xl border border-border bg-card/70 p-3 transition-colors hover:border-primary/40 hover:bg-card">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-status-warning-bg text-status-warning">
                                <Link2 className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-semibold">
                                    Linked incident ·{' '}
                                    {d.related_incident.reference}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {d.related_incident.type
                                        ? titleCase(d.related_incident.type)
                                        : 'Incident'}{' '}
                                    — open in Incidents
                                </div>
                            </div>
                            <ExternalLink className="h-4 w-4 shrink-0 text-muted-foreground" />
                        </div>
                    </Link>
                    {canReview ? (
                        <button
                            type="button"
                            onClick={onLink}
                            className="self-start text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        >
                            Change or remove linked incident
                        </button>
                    ) : null}
                </div>
            ) : canReview ? (
                <button
                    type="button"
                    onClick={onLink}
                    className="flex w-full items-center gap-3 rounded-xl border border-dashed border-border bg-card/40 p-3 text-left transition-colors hover:border-primary/40 hover:bg-card"
                >
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                        <Link2 className="h-4 w-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold">
                            Link an incident
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Cross-reference this restraint episode with a
                            reported incident
                        </div>
                    </div>
                    <Plus className="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>
            ) : null}
        </div>
    );
}

function ResponseSection({ d }: { d: EventDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <Block
                icon={Activity}
                title="Trigger / antecedent"
                body={d.trigger_description}
            />
            <Block
                icon={ShieldCheck}
                title="De-escalation attempted"
                body={d.de_escalation_attempted}
            />
            <Block
                icon={ClipboardList}
                title="Restraint used"
                body={d.restraint_description}
            />
            <Block
                icon={User}
                title="How the person responded"
                body={d.person_response}
            />
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                    <Users className="h-3.5 w-3.5" /> Staff involved
                </div>
                {d.staff_involved.length ? (
                    <div className="flex flex-wrap gap-1.5">
                        {d.staff_involved.map((s, i) => (
                            <span
                                key={s.id ?? i}
                                className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[13px] font-medium"
                            >
                                {s.name}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No staff recorded.
                    </p>
                )}
            </div>
        </div>
    );
}

function InjurySection({ d }: { d: EventDetail }) {
    return (
        <div className="flex flex-col gap-4">
            {d.injury_occurred ? (
                <InfoCard icon={HeartPulse} tone="crit">
                    An injury occurred during this episode — it escalated the
                    event severity and raised a Control Room alert.
                </InfoCard>
            ) : (
                <InfoCard icon={CheckCircle2} tone="info">
                    No injury was recorded for this episode.
                </InfoCard>
            )}
            {d.injury_occurred ? (
                <Block
                    icon={HeartPulse}
                    title="Injury details"
                    body={d.injury_details}
                />
            ) : null}
            <Block
                icon={ShieldCheck}
                title="Post-incident support"
                body={d.post_incident_support}
            />
        </div>
    );
}

function EvidenceSection({ d }: { d: EventDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                Body maps, injury photos, authorisation forms and debrief notes.
                Up to 10&nbsp;MB each.
            </p>
            {d.attachments.length === 0 ? (
                <EmptyState
                    icon={Paperclip}
                    title="No evidence yet"
                    blurb="Attach body maps, injury photos or the authorisation form."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.attachments.map((a) => (
                        <div
                            key={a.id}
                            className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3"
                        >
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                                <Paperclip className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {a.name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {a.category ? (
                                        <span className="font-medium text-foreground">
                                            {ATTACHMENT_CATEGORY_LABEL[
                                                a.category
                                            ] ?? titleCase(a.category)}
                                        </span>
                                    ) : null}
                                    {a.category ? ' · ' : ''}
                                    {formatFileSize(a.size)}
                                    {a.uploaded_by ? ` · ${a.uploaded_by}` : ''}
                                    {a.notes ? ` · ${a.notes}` : ''}
                                </div>
                            </div>
                            <a
                                href={a.download_url}
                                className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                title="Download"
                            >
                                <Download className="h-4 w-4" />
                            </a>
                            {d.can.manage ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            `/health-safety/restraints/events/${d.id}/attachments/${a.id}`,
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                    className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical"
                                    title="Remove"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
            {d.can.review || d.can.manage ? (
                <AttachmentUploader
                    endpoint={`/health-safety/restraints/events/${d.id}/attachments`}
                    noteField="notes"
                    categoryField={{
                        field: 'category',
                        label: 'Category',
                        options: ATTACHMENT_CATEGORY_OPTIONS,
                    }}
                    accept="image/*,.pdf,.doc,.docx"
                    hint="Body maps, injury photos, forms — images, PDF or Word, up to 10 MB each"
                />
            ) : null}
        </div>
    );
}

function ReviewSection({
    d,
    onReview,
}: {
    d: EventDetail;
    onReview: () => void;
}) {
    if (!d.reviewed_at) {
        return (
            <div className="flex flex-col gap-4">
                <EmptyState
                    icon={ClipboardCheck}
                    title="Not reviewed yet"
                    blurb="A reviewer should check this restrictive-practice episode, confirm severity, and capture lessons learned."
                />
                {d.can.review ? (
                    <Button className="self-center" onClick={onReview}>
                        <ClipboardCheck className="mr-1.5 h-4 w-4" /> Review
                        this event
                    </Button>
                ) : null}
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-4">
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <ReviewRow label="Reviewed by" value={d.reviewed_by?.name} />
                <ReviewRow
                    label="Reviewed at"
                    value={fmtDateTime(d.reviewed_at)}
                />
                <ReviewRow
                    label="Confirmed severity"
                    value={severityMeta(d.severity).label}
                />
            </div>
            <Block
                icon={ClipboardCheck}
                title="Review notes"
                body={d.review_notes}
            />
            <Block
                icon={Activity}
                title="Lessons learned"
                body={d.lessons_learned}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Review pane (Add-Client idiom)                                     */
/* ------------------------------------------------------------------ */

function ReviewPane({ d, onDone }: { d: EventDetail; onDone: () => void }) {
    const form = useForm({
        severity: d.severity ?? 'medium',
        review_notes: d.review_notes ?? '',
        lessons_learned: d.lessons_learned ?? '',
        post_incident_support: d.post_incident_support ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/health-safety/restraints/events/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ClipboardCheck}
                title="Review event"
                blurb="Confirm the severity, capture review notes and lessons learned."
            />
            <InfoCard icon={ShieldCheck} tone="info">
                Reviewing closes the loop on a restrictive-practice episode. The
                reviewer and time are recorded automatically.
            </InfoCard>
            <Field
                label="Confirmed severity"
                required
                error={form.errors.severity}
            >
                <SelectInput
                    value={form.data.severity}
                    onChange={(v) => form.setData('severity', v)}
                    placeholder="Select severity"
                    options={SEVERITY_OPTIONS}
                />
            </Field>
            <Field label="Review notes" error={form.errors.review_notes}>
                <Textarea
                    rows={3}
                    value={form.data.review_notes}
                    onChange={(e) =>
                        form.setData('review_notes', e.target.value)
                    }
                    placeholder="Your assessment of the episode"
                />
            </Field>
            <Field label="Lessons learned" error={form.errors.lessons_learned}>
                <Textarea
                    rows={3}
                    value={form.data.lessons_learned}
                    onChange={(e) =>
                        form.setData('lessons_learned', e.target.value)
                    }
                    placeholder="What could reduce restrictive practice next time?"
                />
            </Field>
            <Field
                label="Post-incident support (update)"
                error={form.errors.post_incident_support}
            >
                <Textarea
                    rows={2}
                    value={form.data.post_incident_support}
                    onChange={(e) =>
                        form.setData('post_incident_support', e.target.value)
                    }
                    placeholder="Any further support provided"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save review
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Link-incident pane (Add-Client idiom)                             */
/* ------------------------------------------------------------------ */

function LinkIncidentPane({
    d,
    incidents,
    onDone,
}: {
    d: EventDetail;
    incidents: IncidentOption[];
    onDone: () => void;
}) {
    // Radix <Select.Item> rejects an empty-string value at runtime, so the "no link"
    // choice carries a sentinel that we translate back to null on submit.
    const NONE = '__none__';
    const form = useForm({
        related_incident_id: d.related_incident
            ? String(d.related_incident.id)
            : NONE,
    });

    // Only same-client incidents can be linked (the server enforces this too).
    const clientIncidents = incidents.filter(
        (i) => i.client_id === d.client?.id,
    );
    // Keep the currently-linked incident selectable even if it has aged out of
    // the recent-incidents picker list.
    const merged = [...clientIncidents];
    if (
        d.related_incident &&
        !merged.some((i) => i.id === d.related_incident!.id)
    ) {
        merged.unshift({
            id: d.related_incident.id,
            client_id: d.client?.id ?? null,
            reference: d.related_incident.reference,
            label: `${d.related_incident.reference}${d.related_incident.type ? ` · ${titleCase(d.related_incident.type)}` : ''}`,
        });
    }
    const nothingToPick = merged.length === 0;
    const options = [
        { value: NONE, label: '— No linked incident —' },
        ...merged.map((i) => ({ value: String(i.id), label: i.label })),
    ];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Inertia's useForm().transform() does not support chaining in this version —
        // set the transform, then post on a separate statement (see clients/medical.tsx).
        form.transform((data) => ({
            related_incident_id:
                data.related_incident_id && data.related_incident_id !== NONE
                    ? Number(data.related_incident_id)
                    : null,
        }));
        form.post(`/health-safety/restraints/events/${d.id}/link-incident`, {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Link2}
                title="Link an incident"
                blurb="Connect this restraint episode to the incident it relates to."
            />
            <InfoCard icon={Link2} tone="info">
                Cross-referencing the restraint event with its incident record
                gives reviewers the full picture. Only incidents for{' '}
                {d.client?.name ?? 'this client'} are shown; you can change or
                remove the link at any time.
            </InfoCard>
            <Field
                label="Related incident"
                error={form.errors.related_incident_id}
            >
                {nothingToPick ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-4 text-sm text-muted-foreground">
                        No incidents recorded for{' '}
                        {d.client?.name ?? 'this client'} yet. Raise an incident
                        first, then link it here.
                    </p>
                ) : (
                    <SelectInput
                        value={form.data.related_incident_id}
                        onChange={(v) => form.setData('related_incident_id', v)}
                        placeholder="Select an incident"
                        options={options}
                    />
                )}
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || nothingToPick}
                >
                    Save link
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                  */
/* ------------------------------------------------------------------ */

function Block({
    icon: Icon,
    title,
    body,
}: {
    icon: LucideIcon;
    title: string;
    body: string | null;
}) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3.5 w-3.5" /> {title}
            </div>
            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                {body || <span className="text-muted-foreground">—</span>}
            </p>
        </div>
    );
}

function EmptyState({
    icon: Icon,
    title,
    blurb,
}: {
    icon: LucideIcon;
    title: string;
    blurb: string;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center">
            <Icon className="h-8 w-8 text-muted-foreground" />
            <div className="text-sm font-semibold">{title}</div>
            <p className="max-w-sm text-xs text-muted-foreground">{blurb}</p>
        </div>
    );
}
