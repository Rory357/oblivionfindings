/* eslint-disable no-restricted-syntax -- bespoke detail-modal surfaces (status chips,
 * dashed add-buttons, evidence rows) are intentional custom layouts on semantic tokens,
 * mirroring drill-detail-dialog.tsx + the wizard primitives. */
/* First Aid detail — the over-the-list register modal. Built on WizardShell (section rail
 * = "steps", Step X of Y header, footer Options bar) exactly like drill-detail-dialog.tsx.
 * Write workflows replace the body as panes (Add-Client idiom). Premium evidence upload
 * lives in the Evidence section. */
import { Button } from '@/components/ui/button';
import {
    AttachmentUploader,
    formatFileSize,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateTime, formatDateTimeLong } from '@/lib/datetime';
import {
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
} from '@/pages/health-safety/components/register-row-kit';
import {
    INJURY_TYPES,
    OUTCOMES,
    PERSON_TYPES,
    injuryLabel,
    outcomeLabel,
    outcomeTone,
    personTypeLabel,
} from '@/pages/health-safety/first-aid/options';
import type { Page } from '@inertiajs/core';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Ambulance,
    Check,
    CheckCircle2,
    ClipboardList,
    Clock,
    Download,
    FileText,
    HeartPulse,
    History,
    Link2,
    Paperclip,
    Pencil,
    Plus,
    Stethoscope,
    Trash2,
    User,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type FormEvent } from 'react';

type Opt = { id: number; name: string };
type IncidentOpt = { id: number; reference: string; label: string };

export type FirstAidDetail = {
    id: number;
    reference: string;
    treated_person_name: string;
    treated_person_type: string;
    treatment_date: string | null;
    injury_illness_type: string;
    injury_illness_description: string | null;
    body_part: string | null;
    treatment_given: string | null;
    treatment_outcome: string;
    ambulance_called: boolean;
    first_aider_notes: string | null;
    incident_reported: boolean;
    site: { id: number; name: string } | null;
    site_id: number | null;
    first_aider: { id: number; name: string } | null;
    first_aider_id: number | null;
    treated_person: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    client_id: number | null;
    related_incident: {
        id: number;
        reference: string;
        title: string | null;
    } | null;
    created_by_name: string | null;
    updated_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
    attachments: Array<{
        id: number;
        original_name: string;
        url: string;
        size: number | null;
        mime: string | null;
        is_image: boolean;
        kind: string | null;
        notes: string | null;
        uploaded_by_name: string | null;
        created_at: string | null;
    }>;
    followups: Array<{
        id: number;
        notes: string;
        assigned_to_name: string | null;
        due_at: string | null;
        completed_at: string | null;
        created_by_name: string | null;
        created_at: string | null;
    }>;
    history: Array<{
        id: number;
        timestamp: string | null;
        action: string;
        actor: string | null;
        detail: string;
    }>;
    can: { manage: boolean };
};

export type FirstAidSectionKey =
    | 'overview'
    | 'injury'
    | 'incident'
    | 'followups'
    | 'evidence'
    | 'history';
export type FirstAidActionKey =
    | 'edit'
    | 'link_incident'
    | 'add_followup'
    | 'delete';

type ActivePane =
    | { kind: 'edit' }
    | { kind: 'link_incident' }
    | { kind: 'add_followup' }
    | { kind: 'delete' };

function paneFromAction(action: FirstAidActionKey | null): ActivePane | null {
    switch (action) {
        case 'edit':
            return { kind: 'edit' };
        case 'link_incident':
            return { kind: 'link_incident' };
        case 'add_followup':
            return { kind: 'add_followup' };
        case 'delete':
            return { kind: 'delete' };
        default:
            return null;
    }
}

export function FirstAidDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    sites,
    firstAiders,
    clients,
    incidents,
}: {
    detail: FirstAidDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: FirstAidSectionKey;
    initialAction?: FirstAidActionKey | null;
    sites: Opt[];
    firstAiders: Opt[];
    clients: Opt[];
    incidents: IncidentOpt[];
}) {
    const d = detail;
    const [section, setSection] = useState<FirstAidSectionKey>(initialSection);
    const [pane, setPane] = useState<ActivePane | null>(
        paneFromAction(initialAction),
    );

    // Re-sync derived section/pane when the register re-targets the same open record.
    useEffect(() => {
        setSection(initialSection);
        setPane(paneFromAction(initialAction));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes
    }, [initialSection, initialAction, d.id]);

    const openFollowups = d.followups.filter((f) => !f.completed_at).length;
    const oTone = outcomeTone(d.treatment_outcome);

    const SECTIONS: {
        key: FirstAidSectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Person & treatment',
            icon: FileText,
        },
        {
            key: 'injury',
            label: 'Injury & treatment',
            blurb: injuryLabel(d.injury_illness_type),
            icon: Stethoscope,
        },
        {
            key: 'incident',
            label: 'Incident link',
            blurb: d.related_incident
                ? d.related_incident.reference
                : d.incident_reported
                  ? 'reportable'
                  : 'not linked',
            icon: Link2,
        },
        {
            key: 'followups',
            label: 'Follow-ups',
            blurb: openFollowups > 0 ? `${openFollowups} open` : 'none open',
            icon: ClipboardList,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
            icon: Paperclip,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Audit trail',
            icon: History,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${TONE_BG[oTone]}`}
            >
                <span
                    className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[oTone]}`}
                />{' '}
                {outcomeLabel(d.treatment_outcome)}
            </span>
            {d.ambulance_called ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 font-medium text-status-critical">
                    <Ambulance className="h-3 w-3" /> Ambulance called
                </span>
            ) : null}
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium text-muted-foreground">
                <User className="h-3 w-3" />{' '}
                {personTypeLabel(d.treated_person_type)}
            </span>
        </div>
    );

    const footerEnd = pane ? null : (
        <div className="flex flex-wrap items-center gap-2">
            {d.can.manage ? (
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'add_followup' })}
                    >
                        <Plus className="mr-1.5 h-4 w-4" /> Add follow-up
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'edit' })}
                    >
                        <Pencil className="mr-1.5 h-4 w-4" /> Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'delete' })}
                        className="border-status-critical/40 text-status-critical hover:text-status-critical"
                    >
                        <Trash2 className="mr-1.5 h-4 w-4" /> Archive
                    </Button>
                </>
            ) : (
                <span className="text-xs text-muted-foreground">
                    Read-only — first-aid records are managed by H&amp;S staff.
                </span>
            )}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`First aid ${d.reference}`}
            description={`${injuryLabel(d.injury_illness_type)} — ${outcomeLabel(d.treatment_outcome)}`}
            railIcon={HeartPulse}
            railTitle={d.treated_person_name || d.reference}
            railSub={`${d.reference} · ${personTypeLabel(d.treated_person_type)}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {pane ? (
                <PaneRenderer
                    pane={pane}
                    d={d}
                    sites={sites}
                    firstAiders={firstAiders}
                    clients={clients}
                    incidents={incidents}
                    onDone={() => setPane(null)}
                    onClose={onClose}
                />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} /> : null}
                    {section === 'injury' ? <InjurySection d={d} /> : null}
                    {section === 'incident' ? (
                        <IncidentSection
                            d={d}
                            onLink={() => setPane({ kind: 'link_incident' })}
                        />
                    ) : null}
                    {section === 'followups' ? (
                        <FollowupsSection
                            d={d}
                            onAdd={() => setPane({ kind: 'add_followup' })}
                        />
                    ) : null}
                    {section === 'evidence' ? <EvidenceSection d={d} /> : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d }: { d: FirstAidDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 flex items-center gap-2">
                        <span
                            className={`grid h-9 w-9 shrink-0 place-items-center rounded-lg text-xs font-bold ${entityTone(d.id)}`}
                        >
                            {initials(d.treated_person_name)}
                        </span>
                        <div className="min-w-0">
                            <div className="truncate text-sm font-semibold">
                                {d.treated_person_name || '—'}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {personTypeLabel(d.treated_person_type)}
                            </div>
                        </div>
                    </div>
                    <ReviewRow
                        label="Site"
                        value={
                            d.site ? (
                                <Link
                                    href={`/sites/${d.site.id}`}
                                    className="text-primary hover:underline"
                                >
                                    {d.site.name}
                                </Link>
                            ) : null
                        }
                    />
                    {d.client ? (
                        <ReviewRow
                            label="Client profile"
                            value={
                                <Link
                                    href={`/operations/clients/${d.client.id}`}
                                    className="text-primary hover:underline"
                                >
                                    {d.client.name}
                                </Link>
                            }
                        />
                    ) : null}
                    <ReviewRow
                        label="When"
                        value={formatDateTimeLong(d.treatment_date)}
                    />
                </div>
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <ReviewRow
                        label="First-aider"
                        value={d.first_aider?.name}
                    />
                    <ReviewRow
                        label="Outcome"
                        value={outcomeLabel(d.treatment_outcome)}
                    />
                    <ReviewRow
                        label="Ambulance"
                        value={d.ambulance_called ? 'Yes — 111 called' : 'No'}
                    />
                    <ReviewRow label="Recorded by" value={d.created_by_name} />
                </div>
            </div>
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                    Description
                </div>
                <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                    {d.injury_illness_description || 'No description recorded.'}
                </p>
            </div>
        </div>
    );
}

function InjurySection({ d }: { d: FirstAidDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <ReviewRow
                        label="Injury / illness"
                        value={injuryLabel(d.injury_illness_type)}
                    />
                    <ReviewRow label="Body part" value={d.body_part} />
                </div>
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <ReviewRow
                        label="Outcome"
                        value={outcomeLabel(d.treatment_outcome)}
                    />
                    <ReviewRow
                        label="Ambulance"
                        value={d.ambulance_called ? 'Yes — 111 called' : 'No'}
                    />
                </div>
            </div>
            <Block title="Treatment given" body={d.treatment_given} />
            <Block title="First-aider notes" body={d.first_aider_notes} />
            {d.ambulance_called ? (
                <InfoCard icon={Ambulance} tone="crit">
                    An ambulance was called for this treatment — surfaced for
                    WorkSafe review. Link an incident if this is notifiable.
                </InfoCard>
            ) : null}
        </div>
    );
}

function IncidentSection({
    d,
    onLink,
}: {
    d: FirstAidDetail;
    onLink: () => void;
}) {
    if (d.related_incident) {
        return (
            <div className="flex flex-col gap-4">
                <Link
                    href={`/incidents/${d.related_incident.id}`}
                    className="block"
                >
                    <InfoCard icon={Link2} tone="warn">
                        Linked to incident{' '}
                        <span className="font-semibold">
                            {d.related_incident.reference}
                        </span>
                        {d.related_incident.title
                            ? ` — ${d.related_incident.title}`
                            : ''}
                        .{' '}
                        <span className="font-semibold underline">
                            Open the incident
                        </span>
                        .
                    </InfoCard>
                </Link>
                <p className="text-xs text-muted-foreground">
                    The incident carries the investigation, corrective actions
                    and any WorkSafe notification for this treatment.
                </p>
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-4">
            {d.incident_reported ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    Marked as reportable but not yet linked to a specific
                    incident.
                </InfoCard>
            ) : (
                <EmptyState
                    icon={Link2}
                    title="Not linked to an incident"
                    blurb="Most first aid is treatment-only. Link or escalate to an incident if this needs investigation or WorkSafe notification."
                />
            )}
            {d.can.manage ? (
                <button
                    type="button"
                    onClick={onLink}
                    className="flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-border py-3 text-sm font-semibold text-primary transition-colors hover:bg-primary/5"
                >
                    <Link2 className="h-4 w-4" /> Link to incident
                </button>
            ) : null}
        </div>
    );
}

function FollowupsSection({
    d,
    onAdd,
}: {
    d: FirstAidDetail;
    onAdd: () => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            {d.followups.length === 0 ? (
                <EmptyState
                    icon={ClipboardList}
                    title="No follow-ups yet"
                    blurb="Track post-treatment actions — re-check a wound, lodge the ACC45, notify whānau."
                />
            ) : (
                d.followups.map((f) => (
                    <div
                        key={f.id}
                        className="rounded-xl border border-border bg-card/70 p-3"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <p className="text-sm whitespace-pre-wrap">
                                {f.notes}
                            </p>
                            {f.completed_at ? (
                                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-status-success-bg px-2 py-0.5 text-xs font-medium text-status-success">
                                    <Check className="h-3 w-3" /> Done
                                </span>
                            ) : d.can.manage ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0"
                                    onClick={() =>
                                        router.patch(
                                            `/health-safety/first-aid/${d.id}/followups/${f.id}/complete`,
                                            {},
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                >
                                    <Check className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    Mark done
                                </Button>
                            ) : null}
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            {f.assigned_to_name ? (
                                <span>Assigned to {f.assigned_to_name}</span>
                            ) : null}
                            {f.due_at ? (
                                <span className="inline-flex items-center gap-1">
                                    <Clock className="h-3 w-3" /> Due{' '}
                                    {formatDateTime(f.due_at)}
                                </span>
                            ) : null}
                            {f.created_by_name ? (
                                <span>Added by {f.created_by_name}</span>
                            ) : null}
                        </div>
                    </div>
                ))
            )}
            {d.can.manage ? (
                <button
                    type="button"
                    onClick={onAdd}
                    className="flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-border py-3 text-sm font-semibold text-primary transition-colors hover:bg-primary/5"
                >
                    <Plus className="h-4 w-4" /> Add follow-up
                </button>
            ) : null}
        </div>
    );
}

function EvidenceSection({ d }: { d: FirstAidDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                ACC45 forms, injury photos and treatment notes. Up to 20&nbsp;MB
                each.
            </p>
            {d.attachments.length === 0 ? (
                <EmptyState
                    icon={Paperclip}
                    title="No evidence yet"
                    blurb="Attach the ACC45, an injury photo or a treatment note."
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
                                    {a.original_name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {a.size != null
                                        ? formatFileSize(a.size)
                                        : ''}
                                    {a.uploaded_by_name
                                        ? ` · ${a.uploaded_by_name}`
                                        : ''}
                                    {a.notes ? ` · ${a.notes}` : ''}
                                </div>
                            </div>
                            <a
                                href={a.url}
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
                                            `/health-safety/first-aid/${d.id}/attachments/${a.id}`,
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
            {d.can.manage ? (
                <AttachmentUploader
                    endpoint={`/health-safety/first-aid/${d.id}/attachments`}
                    noteField="notes"
                    accept="image/*,.pdf,.doc,.docx"
                    hint="ACC45 form, injury photos, treatment notes — up to 20 MB each"
                />
            ) : null}
        </div>
    );
}

function HistorySection({ d }: { d: FirstAidDetail }) {
    if (d.history.length === 0) {
        return (
            <EmptyState
                icon={History}
                title="No history yet"
                blurb="Changes to this record appear here as an audit trail."
            />
        );
    }
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <ol className="flex flex-col gap-4">
                {d.history.map((h) => (
                    <li key={h.id} className="flex gap-3">
                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                            <Activity className="h-3.5 w-3.5" />
                        </span>
                        <div className="min-w-0">
                            <div className="text-sm font-semibold">
                                {h.detail}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {[
                                    h.actor,
                                    h.timestamp
                                        ? formatDateTime(h.timestamp)
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || '—'}
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Write panes (Add-Client idiom)                                     */
/* ------------------------------------------------------------------ */

function PaneRenderer({
    pane,
    d,
    sites,
    firstAiders,
    clients,
    incidents,
    onDone,
    onClose,
}: {
    pane: ActivePane;
    d: FirstAidDetail;
    sites: Opt[];
    firstAiders: Opt[];
    clients: Opt[];
    incidents: IncidentOpt[];
    onDone: () => void;
    onClose: () => void;
}) {
    switch (pane.kind) {
        case 'edit':
            return (
                <EditPane
                    d={d}
                    sites={sites}
                    firstAiders={firstAiders}
                    clients={clients}
                    onDone={onDone}
                />
            );
        case 'link_incident':
            return (
                <LinkIncidentPane d={d} incidents={incidents} onDone={onDone} />
            );
        case 'add_followup':
            return (
                <AddFollowupPane
                    d={d}
                    firstAiders={firstAiders}
                    onDone={onDone}
                />
            );
        case 'delete':
            return <DeletePane d={d} onDone={onDone} onClose={onClose} />;
    }
}

function paneSuccess(onDone: () => void) {
    return (page: Page) => {
        if (!(page.props as { flash?: { error?: string } }).flash?.error)
            onDone();
    };
}

function toLocalInput(iso: string | null): string {
    if (!iso) return '';
    const dt = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
}

function EditPane({
    d,
    sites,
    firstAiders,
    clients,
    onDone,
}: {
    d: FirstAidDetail;
    sites: Opt[];
    firstAiders: Opt[];
    clients: Opt[];
    onDone: () => void;
}) {
    const form = useForm({
        site_id: d.site_id ? String(d.site_id) : '',
        treated_person_type: d.treated_person_type,
        treated_person_name: d.treated_person_name,
        client_id: d.client_id ? String(d.client_id) : '',
        treatment_date: toLocalInput(d.treatment_date),
        first_aider_id: d.first_aider_id ? String(d.first_aider_id) : '',
        injury_illness_type: d.injury_illness_type,
        body_part: d.body_part ?? '',
        injury_illness_description: d.injury_illness_description ?? '',
        treatment_given: d.treatment_given ?? '',
        treatment_outcome: d.treatment_outcome,
        ambulance_called: d.ambulance_called,
        first_aider_notes: d.first_aider_notes ?? '',
    });
    const isClient = form.data.treated_person_type === 'client';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            site_id: data.site_id ? Number(data.site_id) : null,
            first_aider_id: data.first_aider_id
                ? Number(data.first_aider_id)
                : null,
            // Only a client treatment carries a client link; switching away nulls it server-side too.
            client_id:
                data.treated_person_type === 'client' && data.client_id
                    ? Number(data.client_id)
                    : null,
            body_part: data.body_part || null,
            first_aider_notes: data.first_aider_notes || null,
        }));
        form.put(`/health-safety/first-aid/${d.id}`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Pencil}
                title="Edit treatment"
                blurb="Update the first-aid record. Changes are tracked in the History tab."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Site" required error={form.errors.site_id}>
                    <SelectInput
                        value={form.data.site_id}
                        onChange={(v) => form.setData('site_id', v)}
                        placeholder="Select site"
                        options={sites.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field
                    label="Treatment date & time"
                    error={form.errors.treatment_date}
                >
                    <Input
                        type="datetime-local"
                        value={form.data.treatment_date}
                        onChange={(e) =>
                            form.setData('treatment_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Person type">
                    <Segmented
                        value={form.data.treated_person_type}
                        onChange={(v) => {
                            if (v !== 'client') form.setData('client_id', '');
                            form.setData('treated_person_type', v);
                        }}
                        options={PERSON_TYPES.map((p) => ({
                            value: p.value,
                            label: p.label,
                        }))}
                    />
                </Field>
                {isClient ? (
                    <Field
                        label="Client treated"
                        required
                        error={form.errors.client_id}
                        hint="links to their profile"
                    >
                        <SelectInput
                            value={form.data.client_id}
                            onChange={(v) => {
                                form.setData('client_id', v);
                                const c = clients.find(
                                    (x) => String(x.id) === v,
                                );
                                if (c)
                                    form.setData('treated_person_name', c.name);
                            }}
                            placeholder="Select client"
                            options={clients.map((c) => ({
                                value: String(c.id),
                                label: c.name,
                            }))}
                        />
                    </Field>
                ) : (
                    <Field
                        label="Person treated"
                        required
                        error={form.errors.treated_person_name}
                    >
                        <Input
                            value={form.data.treated_person_name}
                            onChange={(e) =>
                                form.setData(
                                    'treated_person_name',
                                    e.target.value,
                                )
                            }
                            placeholder="Full name"
                        />
                    </Field>
                )}
                <Field
                    label="First-aider"
                    required
                    error={form.errors.first_aider_id}
                >
                    <SelectInput
                        value={form.data.first_aider_id}
                        onChange={(v) => form.setData('first_aider_id', v)}
                        placeholder="Select first-aider"
                        options={firstAiders.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field
                    label="Injury / illness"
                    required
                    error={form.errors.injury_illness_type}
                >
                    <SelectInput
                        value={form.data.injury_illness_type}
                        onChange={(v) => form.setData('injury_illness_type', v)}
                        placeholder="Select type"
                        options={INJURY_TYPES}
                    />
                </Field>
                <Field label="Body part" error={form.errors.body_part}>
                    <Input
                        value={form.data.body_part}
                        onChange={(e) =>
                            form.setData('body_part', e.target.value)
                        }
                        placeholder="e.g. Left hand"
                    />
                </Field>
                <Field
                    label="Outcome"
                    required
                    error={form.errors.treatment_outcome}
                >
                    <SelectInput
                        value={form.data.treatment_outcome}
                        onChange={(v) => form.setData('treatment_outcome', v)}
                        placeholder="Select outcome"
                        options={OUTCOMES}
                    />
                </Field>
                <Field
                    label="Description"
                    required
                    error={form.errors.injury_illness_description}
                    span
                >
                    <Textarea
                        rows={2}
                        value={form.data.injury_illness_description}
                        onChange={(e) =>
                            form.setData(
                                'injury_illness_description',
                                e.target.value,
                            )
                        }
                    />
                </Field>
                <Field
                    label="Treatment given"
                    required
                    error={form.errors.treatment_given}
                    span
                >
                    <Textarea
                        rows={2}
                        value={form.data.treatment_given}
                        onChange={(e) =>
                            form.setData('treatment_given', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="First-aider notes"
                    error={form.errors.first_aider_notes}
                    span
                >
                    <Textarea
                        rows={2}
                        value={form.data.first_aider_notes}
                        onChange={(e) =>
                            form.setData('first_aider_notes', e.target.value)
                        }
                    />
                </Field>
                <label className="col-span-full flex items-center gap-2.5 text-sm">
                    <Switch
                        checked={form.data.ambulance_called}
                        onCheckedChange={(v) =>
                            form.setData('ambulance_called', v)
                        }
                    />
                    Ambulance was called (111)
                </label>
            </div>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Save changes"
            />
        </form>
    );
}

function LinkIncidentPane({
    d,
    incidents,
    onDone,
}: {
    d: FirstAidDetail;
    incidents: IncidentOpt[];
    onDone: () => void;
}) {
    const form = useForm<{ related_incident_id: string }>({
        related_incident_id: '',
    });
    // A new incident requires a client (client_incidents.client_id is NOT-NULL). Non-client
    // treatments can only link an EXISTING incident.
    const canCreateNew = d.treated_person_type === 'client' && !!d.client_id;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            related_incident_id: data.related_incident_id
                ? Number(data.related_incident_id)
                : null,
        }));
        form.post(`/health-safety/first-aid/${d.id}/link-incident`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Link2}
                title="Link to incident"
                blurb={
                    canCreateNew
                        ? 'Connect this treatment to an existing incident, or create a new one to escalate.'
                        : 'Connect this treatment to an existing incident.'
                }
            />
            <Field
                label="Existing incident"
                required={!canCreateNew}
                hint={
                    canCreateNew
                        ? 'leave blank to create a new incident from this treatment'
                        : 'pick the incident to link'
                }
                error={form.errors.related_incident_id}
            >
                <SelectInput
                    value={form.data.related_incident_id}
                    onChange={(v) => form.setData('related_incident_id', v)}
                    placeholder="Search recent incidents…"
                    options={incidents.map((i) => ({
                        value: String(i.id),
                        label: i.label,
                    }))}
                />
            </Field>
            {canCreateNew ? (
                <InfoCard icon={CheckCircle2} tone="info">
                    Creating a new incident runs the standard incident workflow
                    — investigation, corrective actions and WorkSafe
                    notification — and marks this treatment as reported.
                </InfoCard>
            ) : (
                <InfoCard icon={AlertTriangle} tone="warn">
                    Only client treatments can auto-create a new incident. For
                    staff, visitor or contractor treatments, link an existing
                    incident here.
                </InfoCard>
            )}
            <PaneFooter
                onDone={onDone}
                processing={
                    form.processing ||
                    (!canCreateNew && !form.data.related_incident_id)
                }
                submitLabel={
                    canCreateNew && !form.data.related_incident_id
                        ? 'Create & link incident'
                        : 'Link incident'
                }
            />
        </form>
    );
}

function AddFollowupPane({
    d,
    firstAiders,
    onDone,
}: {
    d: FirstAidDetail;
    firstAiders: Opt[];
    onDone: () => void;
}) {
    const form = useForm({ notes: '', assigned_to_user_id: '', due_at: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            notes: data.notes,
            assigned_to_user_id: data.assigned_to_user_id
                ? Number(data.assigned_to_user_id)
                : null,
            due_at: data.due_at || null,
        }));
        form.post(`/health-safety/first-aid/${d.id}/followups`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Plus}
                title="Add follow-up"
                blurb="Track a post-treatment action — re-check, ACC45, whānau contact."
            />
            <Field label="Follow-up" required error={form.errors.notes}>
                <Textarea
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="What needs to happen next?"
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Assign to"
                    error={form.errors.assigned_to_user_id}
                >
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Unassigned"
                        options={firstAiders.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Due" error={form.errors.due_at}>
                    <Input
                        type="datetime-local"
                        value={form.data.due_at}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </Field>
            </div>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Add follow-up"
            />
        </form>
    );
}

function DeletePane({
    d,
    onDone,
    onClose,
}: {
    d: FirstAidDetail;
    onDone: () => void;
    onClose: () => void;
}) {
    const submit = () => {
        router.delete(`/health-safety/first-aid/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onClose();
            },
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={Trash2}
                title="Archive record"
                blurb="Soft-delete this first-aid record. It is removed from the register but kept for the audit trail."
            />
            <InfoCard icon={AlertTriangle} tone="warn">
                Archiving removes{' '}
                <span className="font-semibold">{d.reference}</span> from the
                register. An administrator can restore it if needed.
            </InfoCard>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="button" variant="destructive" onClick={submit}>
                    <Trash2 className="mr-1.5 h-4 w-4" /> Archive record
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                  */
/* ------------------------------------------------------------------ */

function Block({ title, body }: { title: string; body: string | null }) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {title}
            </div>
            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                {body || '—'}
            </p>
        </div>
    );
}

function PaneFooter({
    onDone,
    processing,
    submitLabel,
}: {
    onDone: () => void;
    processing: boolean;
    submitLabel: string;
}) {
    return (
        <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onDone}>
                Cancel
            </Button>
            <Button type="submit" disabled={processing}>
                {submitLabel}
            </Button>
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
