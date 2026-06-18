import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    AlertTriangle,
    Box,
    Calendar,
    CircleSlash,
    Clock,
    CreditCard,
    ExternalLink,
    MapPin,
    Paperclip,
    RadioTower,
    ShieldAlert,
    Truck,
    User,
    X,
} from 'lucide-react';
import { formatDateTime } from '@/lib/datetime';

/* ------------------------------------------------------------------ */
/*  Detail payload type (matches IncidentController::buildDetailPayload) */
/* ------------------------------------------------------------------ */

export type Ref = { id: number; name: string } | null;

export interface FleetIncidentDetail {
    id: number;
    reference: string;
    incident_type: string;
    severity: string;
    hs_severity: string;
    status: string;
    occurred_at: string | null;
    created_at: string | null;
    location: string | null;
    latitude: number | null;
    longitude: number | null;
    description: string | null;
    resolution_notes: string | null;
    resolved_at: string | null;

    asset: { id: number; name: string; registration_number: string | null; category: string | null; site: { id: number; name: string } | null } | null;
    reported_by: Ref;
    driver: Ref;
    supervisor: Ref;
    assigned_to: Ref;
    booking: { id: number; purpose: string | null; starts_at: string | null; ends_at: string | null } | null;

    asset_category: string | null;
    vehicle_rego_snapshot: string | null;
    wof_status_snapshot: string | null;
    wof_expiry_snapshot: string | null;
    cof_status_snapshot: string | null;
    cof_expiry_snapshot: string | null;
    odometer_at_incident: number | null;
    fuel_type_snapshot: string | null;
    driver_licence_number: string | null;
    driver_licence_class: string | null;
    driver_licence_expiry: string | null;
    driver_years_held: number | null;
    driver_on_duty: boolean | null;

    people_aboard: Array<Record<string, unknown>> | null;
    people_aboard_count: number | null;
    whanau_informed: boolean | null;
    third_party_involved: boolean | null;
    third_parties: Array<Record<string, unknown>> | null;
    witnesses: Array<Record<string, unknown>> | null;
    attending_officer: string | null;

    road_type: string | null;
    weather: string | null;
    lighting: string | null;
    traffic_conditions: string | null;
    speed_limit: number | null;
    estimated_speed: number | null;
    manoeuvre: string | null;
    road_hazard: string | null;

    damage_details: { areas?: string[]; estimated_cost?: number } | null;
    damage_classification: string | null;
    is_drivable: boolean | null;
    tow_required: boolean | null;
    tow_provider: string | null;
    cargo_equipment_damage: string | null;
    vehicle_off_road: boolean | null;
    off_road_from: string | null;
    off_road_to: string | null;
    service_resumed_at: string | null;
    is_off_road: boolean;

    injury_involved: boolean | null;
    fatality_involved: boolean | null;
    injury_severity: string | null;
    requires_police_report: boolean;
    is_police_report_due: boolean;
    police_report_due_at: string | null;
    police_report_hours_remaining: number | null;
    police_report_logged_at: string | null;
    police_notified: boolean | null;
    police_reference: string | null;
    traffic_crash_report_reference: string | null;
    is_notifiable: boolean | null;
    worksafe_notification_status: string | null;
    worksafe_notified_at: string | null;
    worksafe_reference: string | null;
    acc_claim_lodged: boolean | null;
    acc_claim_reference: string | null;
    breath_test_administered: boolean | null;
    breath_test_result: string | null;
    drug_test_administered: boolean | null;
    drug_test_result: string | null;

    insurance_claimed: boolean | null;
    insurance_reference: string | null;
    insurer_name: string | null;
    insurance_excess: string | number | null;
    insurance_amount_sought: string | number | null;
    insurance_amount_approved: string | number | null;
    insurance_claim_status: string | null;
    repair_contractor: string | null;
    actual_repair_cost: string | number | null;
    total_incident_cost: string | number | null;

    root_cause: string | null;
    corrective_actions: string | null;
    contributing_factors: string[] | null;
    investigation_completed_at: string | null;

    asset_serial_snapshot: string | null;
    asset_condition_before: string | null;
    asset_condition_after: string | null;
    warranty_status: string | null;
    replacement_cost: string | number | null;

    potential_severity: string | null;

    attachments: Array<{ id: number; original_name: string; url: string; mime: string | null; kind: string | null; notes: string | null; alt_text: string | null; size: number | null; is_image: boolean; uploaded_by: Ref; created_at: string | null }>;
    followups: Array<{ id: number; notes: string | null; assigned_to: Ref; created_by: Ref; due_at: string | null; completed_at: string | null; is_completed: boolean }>;
    client_incidents: Array<{ id: number; client: { id: number; name: string } | null; severity: string | null; status: string | null; type: string | null }>;
    hs_event: { id: number; reference: string | null; status: string | null; control_room_alert_id: number | null } | null;

    can: { manage: boolean };
}

const SEV_PILL: Record<string, string> = {
    minor: 'bg-status-success-bg text-status-success',
    moderate: 'bg-status-warning-bg text-status-warning',
    major: 'bg-status-critical-bg text-status-critical',
    critical: 'bg-status-critical-bg text-status-critical',
};
const STATUS_PILL: Record<string, string> = {
    reported: 'bg-status-info-bg text-status-info',
    investigating: 'bg-status-warning-bg text-status-warning',
    resolved: 'bg-primary/10 text-primary',
    closed: 'bg-status-success-bg text-status-success',
};

function titleCase(s: string | null): string {
    return (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Fleet incident detail — read-only modal over the list. Step 4 rebuilds this on
 * the WizardShell rail/Options chrome with in-place workflow sub-modals; this is
 * the genuine read-only view it grows from.
 */
export function FleetIncidentDialog({
    detail,
    open,
    onClose,
}: {
    detail: FleetIncidentDetail;
    open: boolean;
    onClose: () => void;
    canManage: boolean;
    onChanged: () => void;
}) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!open) return null;

    const peopleCount = detail.people_aboard_count ?? detail.people_aboard?.length ?? 0;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label={`Incident ${detail.reference}`} onClick={onClose}>
            <div className="my-4 w-full max-w-3xl rounded-2xl border border-border bg-card shadow-xl" onClick={(e) => e.stopPropagation()}>
                {/* Header */}
                <div className="flex items-start justify-between gap-4 border-b border-border p-5">
                    <div className="flex items-start gap-3">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            {detail.asset?.category && detail.asset.category !== 'vehicle' ? <Box className="h-5 w-5" /> : <Truck className="h-5 w-5" />}
                        </span>
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold text-foreground">{titleCase(detail.incident_type)}</h2>
                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${SEV_PILL[detail.severity] ?? 'bg-muted'}`}>{titleCase(detail.severity)}</span>
                                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium capitalize ${STATUS_PILL[detail.status] ?? 'bg-muted'}`}>{detail.status}</span>
                            </div>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                <span className="font-mono">{detail.reference}</span>
                                {detail.asset ? ` · ${detail.asset.name}` : ''}
                                {detail.asset?.registration_number ? ` · ${detail.asset.registration_number}` : ''}
                                {detail.occurred_at ? ` · ${formatDateTime(detail.occurred_at)}` : ''}
                                {detail.asset?.site ? ` · ${detail.asset.site.name}` : ''}
                            </p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close" className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="max-h-[70vh] space-y-5 overflow-y-auto p-5">
                    {/* s22 Police-report countdown banner */}
                    {detail.is_police_report_due ? (
                        <Banner tone="critical" icon={<ShieldAlert className="h-4 w-4" />}>
                            <strong>Police report due.</strong> Land Transport Act 1998 s22 requires an injury/fatal crash to be reported to NZ Police within 24 hours (105 / a Traffic Crash Report).
                            {detail.police_report_due_at ? ` Due by ${formatDateTime(detail.police_report_due_at)}.` : ''}
                        </Banner>
                    ) : null}
                    {detail.is_notifiable && detail.worksafe_notification_status !== 'notified' && detail.worksafe_notification_status !== 'acknowledged' ? (
                        <Banner tone="warning" icon={<AlertTriangle className="h-4 w-4" />}>
                            <strong>WorkSafe-notifiable.</strong> This event meets the HSWA 2015 notifiable threshold — notify WorkSafe NZ as soon as possible.
                        </Banner>
                    ) : null}

                    {/* Overview */}
                    <Section title="Overview">
                        {detail.description ? <p className="text-sm text-foreground">{detail.description}</p> : null}
                        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <Field label="Occurred" value={detail.occurred_at ? formatDateTime(detail.occurred_at) : '—'} icon={<Calendar className="h-3.5 w-3.5" />} />
                            <Field label="Location" value={detail.location ?? '—'} icon={<MapPin className="h-3.5 w-3.5" />} />
                            <Field label="Reported by" value={detail.reported_by?.name ?? '—'} />
                            <Field label="Driver" value={detail.driver?.name ?? '—'} icon={<User className="h-3.5 w-3.5" />} />
                            {detail.assigned_to ? <Field label="Investigation owner" value={detail.assigned_to.name} /> : null}
                        </dl>
                    </Section>

                    {/* Vehicle / asset */}
                    {detail.asset ? (
                        <Section title={detail.asset.category && detail.asset.category !== 'vehicle' ? 'Asset' : 'Vehicle'}>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <Field label="Name" value={detail.asset.name} />
                                <Field label="Rego" value={detail.vehicle_rego_snapshot ?? detail.asset.registration_number ?? '—'} />
                                {detail.odometer_at_incident != null ? <Field label="Odometer" value={`${detail.odometer_at_incident} km`} /> : null}
                                {detail.wof_status_snapshot ? <Field label="WoF/CoF" value={detail.wof_status_snapshot} /> : null}
                                {detail.asset_serial_snapshot ? <Field label="Serial" value={detail.asset_serial_snapshot} /> : null}
                            </dl>
                            <button type="button" onClick={() => router.visit(`/fleet-assets/assets/${detail.asset!.id}`)} className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                Open in Asset register <ExternalLink className="h-3 w-3" />
                            </button>
                        </Section>
                    ) : null}

                    {/* People */}
                    {peopleCount > 0 || detail.third_party_involved || (detail.witnesses?.length ?? 0) > 0 ? (
                        <Section title="People">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <Field label="People aboard" value={peopleCount ? String(peopleCount) : '—'} />
                                <Field label="Whānau informed" value={detail.whanau_informed ? 'Yes' : 'No'} />
                                <Field label="Third party involved" value={detail.third_party_involved ? 'Yes' : 'No'} />
                                <Field label="Witnesses" value={String(detail.witnesses?.length ?? 0)} />
                            </dl>
                        </Section>
                    ) : null}

                    {/* Police & regulatory */}
                    <Section title="Police & regulatory (NZ)">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <Field label="Injury / fatality" value={detail.fatality_involved ? 'Fatality' : detail.injury_involved ? `Injury (${titleCase(detail.injury_severity)})` : 'None'} />
                            <Field label="Police notified" value={detail.police_notified ? 'Yes' : 'No'} />
                            <Field label="TCR reference" value={detail.traffic_crash_report_reference ?? '—'} />
                            <Field label="WorkSafe" value={detail.is_notifiable ? titleCase(detail.worksafe_notification_status ?? 'pending') : 'Not notifiable'} />
                            <Field label="ACC claim" value={detail.acc_claim_lodged ? (detail.acc_claim_reference ?? 'Lodged') : '—'} />
                        </dl>
                    </Section>

                    {/* Damage & recovery */}
                    {detail.damage_classification || detail.vehicle_off_road || detail.damage_details?.areas?.length ? (
                        <Section title="Damage & recovery">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <Field label="Classification" value={titleCase(detail.damage_classification) || '—'} />
                                <Field label="Drivable" value={detail.is_drivable == null ? '—' : detail.is_drivable ? 'Yes' : 'No'} />
                                {detail.is_off_road ? <Field label="Off-road (VOR)" value={`From ${detail.off_road_from ?? '—'}`} icon={<CircleSlash className="h-3.5 w-3.5" />} /> : null}
                                {detail.damage_details?.areas?.length ? <Field label="Areas" value={detail.damage_details.areas.join(', ')} /> : null}
                            </dl>
                        </Section>
                    ) : null}

                    {/* Insurance */}
                    {detail.insurance_claimed ? (
                        <Section title="Insurance & cost">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <Field label="Insurer" value={detail.insurer_name ?? '—'} icon={<CreditCard className="h-3.5 w-3.5" />} />
                                <Field label="Claim ref" value={detail.insurance_reference ?? '—'} />
                                <Field label="Status" value={titleCase(detail.insurance_claim_status) || '—'} />
                                {detail.total_incident_cost ? <Field label="Total cost" value={`$${detail.total_incident_cost}`} /> : null}
                            </dl>
                        </Section>
                    ) : null}

                    {/* Evidence */}
                    {detail.attachments.length > 0 ? (
                        <Section title={`Photos & documents (${detail.attachments.length})`}>
                            <div className="grid grid-cols-3 gap-2">
                                {detail.attachments.map((a) => (
                                    <a key={a.id} href={`/fleet-assets/incidents/${detail.id}/attachments/${a.id}/download`} className="group relative block overflow-hidden rounded-lg border border-border" title={a.notes ?? a.original_name}>
                                        {a.is_image ? (
                                            <img src={a.url} alt={a.alt_text ?? a.original_name} className="h-24 w-full object-cover" />
                                        ) : (
                                            <span className="flex h-24 w-full items-center justify-center bg-muted text-muted-foreground">
                                                <Paperclip className="h-5 w-5" />
                                            </span>
                                        )}
                                        <span className="block truncate px-1.5 py-1 text-[11px] text-muted-foreground">{a.original_name}</span>
                                    </a>
                                ))}
                            </div>
                        </Section>
                    ) : null}

                    {/* Investigation & follow-ups */}
                    {detail.root_cause || detail.followups.length > 0 ? (
                        <Section title="Investigation & follow-ups">
                            {detail.root_cause ? <p className="text-sm text-foreground"><span className="font-medium">Root cause:</span> {detail.root_cause}</p> : null}
                            {detail.followups.length > 0 ? (
                                <ul className="mt-2 space-y-1.5">
                                    {detail.followups.map((f) => (
                                        <li key={f.id} className="flex items-start gap-2 text-sm">
                                            <Clock className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${f.is_completed ? 'text-status-success' : 'text-status-warning'}`} />
                                            <span className={f.is_completed ? 'text-muted-foreground line-through' : 'text-foreground'}>
                                                {f.notes}
                                                {f.assigned_to ? ` · ${f.assigned_to.name}` : ''}
                                                {f.due_at ? ` · due ${formatDateTime(f.due_at)}` : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </Section>
                    ) : null}

                    {/* Linked records */}
                    {detail.hs_event || detail.client_incidents.length > 0 ? (
                        <Section title="Linked records">
                            {detail.hs_event ? (
                                <button type="button" onClick={() => router.visit(`/health-safety/events/${detail.hs_event!.id}`)} className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                                    <ShieldAlert className="h-3.5 w-3.5" /> H&S event {detail.hs_event.reference ?? `#${detail.hs_event.id}`} <ExternalLink className="h-3 w-3" />
                                </button>
                            ) : null}
                            {detail.hs_event?.control_room_alert_id ? (
                                <button type="button" onClick={() => router.visit(`/control-room/alerts/${detail.hs_event!.control_room_alert_id}`)} className="ml-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                                    <RadioTower className="h-3.5 w-3.5" /> Control Room alert <ExternalLink className="h-3 w-3" />
                                </button>
                            ) : null}
                            {detail.client_incidents.length > 0 ? (
                                <ul className="mt-2 space-y-1">
                                    {detail.client_incidents.map((ci) => (
                                        <li key={ci.id}>
                                            <button type="button" onClick={() => router.visit(`/incidents?incident=${ci.id}`)} className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                                                <User className="h-3.5 w-3.5" /> {ci.client?.name ?? 'Resident'} — transport incident ({titleCase(ci.status)}) <ExternalLink className="h-3 w-3" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </Section>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section>
            <h3 className="mb-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{title}</h3>
            {children}
        </section>
    );
}

function Field({ label, value, icon }: { label: string; value: string; icon?: React.ReactNode }) {
    return (
        <div>
            <dt className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="flex items-center gap-1 font-medium text-foreground">
                {icon ? <span className="text-muted-foreground">{icon}</span> : null}
                {value}
            </dd>
        </div>
    );
}

function Banner({ tone, icon, children }: { tone: 'critical' | 'warning'; icon: React.ReactNode; children: React.ReactNode }) {
    const cls = tone === 'critical' ? 'border-status-critical/40 bg-status-critical-bg/60 text-status-critical' : 'border-status-warning/40 bg-status-warning-bg/60 text-status-warning';
    return (
        <div className={`flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm ${cls}`}>
            <span className="mt-0.5 shrink-0">{icon}</span>
            <p className="text-foreground/90">{children}</p>
        </div>
    );
}
