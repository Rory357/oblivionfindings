import { Link, router, useForm } from '@inertiajs/react';
import { useState, type ComponentType, type FormEvent } from 'react';
import {
    AlertTriangle,
    Box,
    Briefcase,
    Calendar,
    CheckCircle2,
    CircleSlash,
    Cloud,
    CreditCard,
    Download,
    ExternalLink,
    FileText,
    Link2,
    ListTodo,
    MapPin,
    Paperclip,
    Plus,
    Search,
    ShieldAlert,
    Trash2,
    Truck,
    User,
    Users,
    Wrench,
} from 'lucide-react';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
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

    asset: {
        id: number;
        name: string;
        registration_number: string | null;
        category: string | null;
        site: { id: number; name: string } | null;
        /** HR-register wrapper of the asset (federation) + its current holder. */
        hr_asset?: { id: number; holder_name: string | null } | null;
    } | null;
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

    can: { manage: boolean; view_hr_assets?: boolean };
}

type UserOption = { id: number; name: string };

const SEV_DOT: Record<string, string> = {
    minor: 'bg-status-success',
    moderate: 'bg-status-warning',
    major: 'bg-status-critical',
    critical: 'bg-status-critical',
};

function titleCase(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

type SectionKey = 'overview' | 'vehicle' | 'people' | 'scene' | 'damage' | 'police' | 'insurance' | 'photos' | 'investigation' | 'linked';
type ActionKey = 'status' | 'followup' | 'police_report' | 'claim' | 'off_road' | 'back_in_service';

/* ------------------------------------------------------------------ */
/*  Detail dialog (WizardShell read-only chrome)                       */
/* ------------------------------------------------------------------ */

export function FleetIncidentDialog({
    detail,
    open,
    onClose,
    users = [],
}: {
    detail: FleetIncidentDetail;
    open: boolean;
    onClose: () => void;
    users?: UserOption[];
}) {
    const d = detail;
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<ActionKey | null>(null);

    const openFollowups = d.followups.filter((f) => !f.is_completed).length;

    const SECTIONS: (WizardStep & { key: SectionKey })[] = [
        { key: 'overview', label: 'Overview', blurb: 'What happened', icon: FileText },
        { key: 'vehicle', label: 'Vehicle / asset', blurb: d.asset?.name ?? 'No asset', icon: Truck },
        { key: 'people', label: 'People', blurb: `${d.people_aboard_count ?? d.people_aboard?.length ?? 0} aboard`, icon: Users },
        { key: 'scene', label: 'Scene & conditions', blurb: d.road_type ?? 'Location & road', icon: MapPin },
        { key: 'damage', label: 'Damage & recovery', blurb: d.damage_classification ? titleCase(d.damage_classification) : 'VOR / tow', icon: Wrench },
        { key: 'police', label: 'Police & regulatory', blurb: d.requires_police_report ? 's22 duty' : 'NZ regs', icon: ShieldAlert },
        { key: 'insurance', label: 'Insurance & cost', blurb: d.insurer_name ?? 'Claim / cost', icon: CreditCard },
        { key: 'photos', label: 'Photos & documents', blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`, icon: Paperclip },
        { key: 'investigation', label: 'Investigation & follow-ups', blurb: openFollowups > 0 ? `${openFollowups} open` : 'Root cause', icon: Search },
        { key: 'linked', label: 'Linked records', blurb: 'H&S · incidents · asset', icon: Link2 },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${SEV_DOT[d.severity] ?? 'bg-muted-foreground'}`} />
                {titleCase(d.severity)}
            </span>
            <span className="text-muted-foreground capitalize">{d.status}</span>
            {d.is_off_road ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-medium text-status-critical">
                    <CircleSlash className="h-3 w-3" /> VOR
                </span>
            ) : null}
        </div>
    );

    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center gap-2">
            <Link href={`/fleet-assets/incidents/${d.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.can.manage ? (
                <>
                    {d.status === 'reported' || d.status === 'investigating' || d.status === 'resolved' ? (
                        <Button size="sm" onClick={() => setAction('status')}>
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            {d.status === 'reported' ? 'Start investigation' : d.status === 'investigating' ? 'Resolve' : 'Close'}
                        </Button>
                    ) : null}
                    <Button size="sm" variant="outline" onClick={() => setAction('followup')}>
                        <Plus className="mr-1.5 h-4 w-4" /> Follow-up
                    </Button>
                    {d.is_police_report_due ? (
                        <Button size="sm" variant="outline" onClick={() => setAction('police_report')}>
                            <ShieldAlert className="mr-1.5 h-4 w-4" /> Log Police report
                        </Button>
                    ) : null}
                    {!d.insurance_claimed ? (
                        <Button size="sm" variant="outline" onClick={() => setAction('claim')}>
                            <CreditCard className="mr-1.5 h-4 w-4" /> Log claim
                        </Button>
                    ) : null}
                    {d.is_off_road ? (
                        <Button size="sm" variant="outline" onClick={() => setAction('back_in_service')}>
                            <CircleSlash className="mr-1.5 h-4 w-4" /> Back in service
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => setAction('off_road')}>
                            <CircleSlash className="mr-1.5 h-4 w-4" /> Mark off-road
                        </Button>
                    )}
                </>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Fleet incident ${d.reference}`}
            description={`${titleCase(d.incident_type)} — ${d.asset?.name ?? 'no asset'}`}
            railIcon={d.asset?.category && d.asset.category !== 'vehicle' ? Box : Truck}
            railTitle={d.asset?.name ?? 'Fleet incident'}
            railSub={`${d.reference} · ${titleCase(d.incident_type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setSection(SECTIONS[i].key);
                setAction(null);
            }}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action ? (
                <ActionPane incidentId={d.id} action={action} d={d} users={users} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' && <OverviewSection d={d} />}
                    {section === 'vehicle' && <VehicleSection d={d} />}
                    {section === 'people' && <PeopleSection d={d} />}
                    {section === 'scene' && <SceneSection d={d} />}
                    {section === 'damage' && <DamageSection d={d} />}
                    {section === 'police' && <PoliceSection d={d} />}
                    {section === 'insurance' && <InsuranceSection d={d} />}
                    {section === 'photos' && <PhotosSection d={d} />}
                    {section === 'investigation' && <InvestigationSection d={d} />}
                    {section === 'linked' && <LinkedSection d={d} />}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

const STAGES = ['reported', 'investigating', 'resolved', 'closed'] as const;
const STAGE_IDX: Record<string, number> = { reported: 0, investigating: 1, resolved: 2, closed: 3 };

function StageTracker({ status }: { status: string }) {
    const cur = STAGE_IDX[status] ?? 0;
    return (
        <div className="flex items-center gap-1">
            {STAGES.map((s, i) => (
                <div key={s} className="flex flex-1 flex-col items-center gap-1">
                    <div className={`h-1.5 w-full rounded-full ${i <= cur ? 'bg-primary' : 'bg-muted'}`} />
                    <span className={`text-[10px] font-semibold ${i === cur ? 'text-primary' : 'text-muted-foreground'}`}>{titleCase(s)}</span>
                </div>
            ))}
        </div>
    );
}

function OverviewSection({ d }: { d: FleetIncidentDetail }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {d.is_police_report_due ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="crit">
                        <strong>Police report due (s22 LTA 1998).</strong> Injury/fatal crashes must be reported to NZ Police within 24 hours via 105 or a Traffic Crash Report.
                        {d.police_report_due_at ? ` Due ${formatDateTime(d.police_report_due_at)}.` : ''}
                        {d.police_report_hours_remaining != null ? ` ${d.police_report_hours_remaining}h remaining.` : ''}
                    </InfoCard>
                </div>
            ) : null}
            {d.is_notifiable && d.worksafe_notification_status !== 'notified' && d.worksafe_notification_status !== 'acknowledged' ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={AlertTriangle} tone="warn">
                        <strong>WorkSafe NZ notifiable event (HSWA 2015).</strong> Notify WorkSafe NZ as soon as practicable.
                    </InfoCard>
                </div>
            ) : null}

            <ReviewCard icon={FileText} title="What happened" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.description || '—'}</p>
            </ReviewCard>

            <ReviewCard icon={Users} title="People">
                <ReviewRow label="Reported by" value={d.reported_by?.name} />
                <ReviewRow label="Driver" value={d.driver?.name} />
                <ReviewRow label="Supervisor" value={d.supervisor?.name} />
                <ReviewRow label="Investigation owner" value={d.assigned_to?.name} />
            </ReviewCard>

            <ReviewCard icon={Calendar} title="When & where">
                <ReviewRow label="Occurred" value={d.occurred_at ? formatDateTime(d.occurred_at) : undefined} />
                <ReviewRow label="Location" value={d.location} />
                <ReviewRow label="Site" value={d.asset?.site?.name} />
            </ReviewCard>

            <div className="sm:col-span-2">
                <StageTracker status={d.status} />
            </div>
        </div>
    );
}

function VehicleSection({ d }: { d: FleetIncidentDetail }) {
    if (!d.asset) return <EmptyState icon={Truck} text="No asset linked to this incident." />;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Truck} title="Asset" span>
                <ReviewRow label="Name" value={d.asset.name} />
                <ReviewRow label="Registration" value={d.vehicle_rego_snapshot ?? d.asset.registration_number} />
                <ReviewRow label="Category" value={titleCase(d.asset.category)} />
                <ReviewRow label="Serial" value={d.asset_serial_snapshot} />
                <ReviewRow label="Odometer" value={d.odometer_at_incident != null ? `${d.odometer_at_incident} km` : undefined} />
                <ReviewRow label="Fuel type" value={d.fuel_type_snapshot} />
            </ReviewCard>
            <ReviewCard icon={FileText} title="Certification (snapshot)">
                <ReviewRow label="WoF status" value={d.wof_status_snapshot} />
                <ReviewRow label="WoF expiry" value={d.wof_expiry_snapshot} />
                <ReviewRow label="CoF status" value={d.cof_status_snapshot} />
                <ReviewRow label="CoF expiry" value={d.cof_expiry_snapshot} />
                <ReviewRow label="Warranty" value={d.warranty_status} />
            </ReviewCard>
            <ReviewCard icon={User} title="Driver">
                <ReviewRow label="Name" value={d.driver?.name} />
                <ReviewRow label="Licence no." value={d.driver_licence_number} />
                <ReviewRow label="Licence class" value={d.driver_licence_class} />
                <ReviewRow label="Expiry" value={d.driver_licence_expiry} />
                <ReviewRow label="Years held" value={d.driver_years_held != null ? String(d.driver_years_held) : undefined} />
                <ReviewRow label="On duty" value={d.driver_on_duty != null ? (d.driver_on_duty ? 'Yes' : 'No') : undefined} />
            </ReviewCard>
            <div className="sm:col-span-2">
                <Link href={`/fleet-assets/assets/${d.asset.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                    <ExternalLink className="h-3.5 w-3.5" /> Open in Asset register
                </Link>
                <span className="ml-3 text-xs text-muted-foreground">Snapshot fields populate from the register once the wider Fleet &amp; Assets module is wired.</span>
            </div>
        </div>
    );
}

function PeopleSection({ d }: { d: FleetIncidentDetail }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Users} title="Aboard">
                <ReviewRow label="People aboard" value={String(d.people_aboard_count ?? d.people_aboard?.length ?? 0)} />
                <ReviewRow label="Whānau informed" value={d.whanau_informed != null ? (d.whanau_informed ? 'Yes' : 'No') : undefined} />
            </ReviewCard>
            <ReviewCard icon={Users} title="Third parties & witnesses">
                <ReviewRow label="Third party involved" value={d.third_party_involved != null ? (d.third_party_involved ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Third parties" value={String(d.third_parties?.length ?? 0)} />
                <ReviewRow label="Witnesses" value={String(d.witnesses?.length ?? 0)} />
                <ReviewRow label="Attending officer" value={d.attending_officer} />
            </ReviewCard>
            {d.booking ? (
                <ReviewCard icon={Calendar} title="Booking context">
                    <ReviewRow label="Purpose" value={d.booking.purpose} />
                    <ReviewRow label="Starts" value={d.booking.starts_at ? formatDateTime(d.booking.starts_at) : undefined} />
                    <ReviewRow label="Ends" value={d.booking.ends_at ? formatDateTime(d.booking.ends_at) : undefined} />
                </ReviewCard>
            ) : null}
            <ReviewCard icon={ShieldAlert} title="Testing">
                <ReviewRow label="Breath test" value={d.breath_test_administered ? (d.breath_test_result ?? 'Administered') : 'Not administered'} />
                <ReviewRow label="Drug test" value={d.drug_test_administered ? (d.drug_test_result ?? 'Administered') : 'Not administered'} />
            </ReviewCard>
        </div>
    );
}

function SceneSection({ d }: { d: FleetIncidentDetail }) {
    const hasCoords = d.latitude != null && d.longitude != null;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={MapPin} title="Location">
                <ReviewRow label="Address" value={d.location} />
                <ReviewRow label="Latitude" value={d.latitude != null ? String(d.latitude) : undefined} />
                <ReviewRow label="Longitude" value={d.longitude != null ? String(d.longitude) : undefined} />
                {hasCoords ? (
                    <div className="mt-2 flex items-center gap-3">
                        <a href={`https://maps.google.com/?q=${d.latitude},${d.longitude}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                            <ExternalLink className="h-3 w-3" /> Google Maps
                        </a>
                        <Link href="/fleet-assets/map" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                            <MapPin className="h-3 w-3" /> Live map
                        </Link>
                    </div>
                ) : null}
            </ReviewCard>
            <ReviewCard icon={Cloud} title="Road & weather">
                <ReviewRow label="Road type" value={d.road_type} />
                <ReviewRow label="Weather" value={d.weather} />
                <ReviewRow label="Lighting" value={d.lighting} />
                <ReviewRow label="Traffic" value={d.traffic_conditions} />
                <ReviewRow label="Speed limit" value={d.speed_limit != null ? `${d.speed_limit} km/h` : undefined} />
                <ReviewRow label="Estimated speed" value={d.estimated_speed != null ? `${d.estimated_speed} km/h` : undefined} />
                <ReviewRow label="Manoeuvre" value={d.manoeuvre} />
                <ReviewRow label="Road hazard" value={d.road_hazard} />
            </ReviewCard>
        </div>
    );
}

function DamageSection({ d }: { d: FleetIncidentDetail }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Wrench} title="Damage" span>
                <ReviewRow label="Classification" value={titleCase(d.damage_classification)} />
                <ReviewRow label="Drivable" value={d.is_drivable != null ? (d.is_drivable ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Tow required" value={d.tow_required != null ? (d.tow_required ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Tow provider" value={d.tow_provider} />
                <ReviewRow label="Damage areas" value={d.damage_details?.areas?.join(', ')} />
                <ReviewRow label="Cargo / equipment" value={d.cargo_equipment_damage} />
            </ReviewCard>
            <ReviewCard icon={CircleSlash} title="Vehicle off-road (VOR)">
                {d.is_off_road || d.vehicle_off_road ? (
                    <>
                        <ReviewRow label="Off-road from" value={d.off_road_from} />
                        <ReviewRow label="Off-road to" value={d.off_road_to ?? '(still off-road)'} />
                        <ReviewRow label="Service resumed" value={d.service_resumed_at} />
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">Vehicle is in service.</p>
                )}
            </ReviewCard>
            <ReviewCard icon={FileText} title="Asset condition & cost">
                <ReviewRow label="Condition before" value={d.asset_condition_before} />
                <ReviewRow label="Condition after" value={d.asset_condition_after} />
                <ReviewRow label="Replacement cost" value={d.replacement_cost != null ? `$${d.replacement_cost}` : undefined} />
                <ReviewRow label="Estimated damage" value={d.damage_details?.estimated_cost != null ? `$${d.damage_details.estimated_cost}` : undefined} />
            </ReviewCard>
        </div>
    );
}

function PoliceSection({ d }: { d: FleetIncidentDetail }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {d.is_police_report_due ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="crit">
                        <strong>s22 LTA 1998 — Police report due.</strong>{' '}
                        {d.police_report_due_at ? `Due by ${formatDateTime(d.police_report_due_at)}.` : ''}
                        {d.police_report_hours_remaining != null ? ` ${d.police_report_hours_remaining}h remaining.` : ''}
                    </InfoCard>
                </div>
            ) : null}
            <ReviewCard icon={ShieldAlert} title="Injury & fatality">
                <ReviewRow label="Injury" value={d.injury_involved != null ? (d.injury_involved ? `Yes — ${titleCase(d.injury_severity)}` : 'No') : undefined} />
                <ReviewRow label="Fatality" value={d.fatality_involved != null ? (d.fatality_involved ? 'Yes' : 'No') : undefined} />
            </ReviewCard>
            <ReviewCard icon={FileText} title="NZ Police">
                <ReviewRow label="Notified" value={d.police_notified != null ? (d.police_notified ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Attending officer" value={d.attending_officer} />
                <ReviewRow label="Police reference" value={d.police_reference} />
                <ReviewRow label="TCR reference" value={d.traffic_crash_report_reference} />
                <ReviewRow label="Logged at" value={d.police_report_logged_at ? formatDateTime(d.police_report_logged_at) : undefined} />
            </ReviewCard>
            <ReviewCard icon={AlertTriangle} title="WorkSafe NZ">
                <ReviewRow label="Notifiable" value={d.is_notifiable != null ? (d.is_notifiable ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Status" value={titleCase(d.worksafe_notification_status)} />
                <ReviewRow label="Notified at" value={d.worksafe_notified_at ? formatDateTime(d.worksafe_notified_at) : undefined} />
                <ReviewRow label="Reference" value={d.worksafe_reference} />
            </ReviewCard>
            <ReviewCard icon={FileText} title="ACC">
                <ReviewRow label="Claim lodged" value={d.acc_claim_lodged != null ? (d.acc_claim_lodged ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Reference" value={d.acc_claim_reference} />
            </ReviewCard>
        </div>
    );
}

function InsuranceSection({ d }: { d: FleetIncidentDetail }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={CreditCard} title="Claim">
                <ReviewRow label="Claimed" value={d.insurance_claimed != null ? (d.insurance_claimed ? 'Yes' : 'No') : undefined} />
                <ReviewRow label="Insurer" value={d.insurer_name} />
                <ReviewRow label="Reference" value={d.insurance_reference} />
                <ReviewRow label="Status" value={titleCase(d.insurance_claim_status)} />
                <ReviewRow label="Excess" value={d.insurance_excess != null ? `$${d.insurance_excess}` : undefined} />
                <ReviewRow label="Amount sought" value={d.insurance_amount_sought != null ? `$${d.insurance_amount_sought}` : undefined} />
                <ReviewRow label="Amount approved" value={d.insurance_amount_approved != null ? `$${d.insurance_amount_approved}` : undefined} />
            </ReviewCard>
            <ReviewCard icon={Wrench} title="Repair & cost">
                <ReviewRow label="Repair contractor" value={d.repair_contractor} />
                <ReviewRow label="Actual repair cost" value={d.actual_repair_cost != null ? `$${d.actual_repair_cost}` : undefined} />
                <ReviewRow label="Total incident cost" value={d.total_incident_cost != null ? `$${d.total_incident_cost}` : undefined} />
            </ReviewCard>
        </div>
    );
}

function PhotosSection({ d }: { d: FleetIncidentDetail }) {
    const canUpload = d.can.manage;
    return (
        <div className="flex flex-col gap-3">
            {canUpload ? (
                <AttachmentUploader endpoint={`/fleet-assets/incidents/${d.id}/attachments`} noteField="notes" accept="image/*,.pdf,.doc,.docx" hint="Scene/damage photos, dashcam, the TCR or insurance PDF — up to 20 MB each" />
            ) : null}

            {d.attachments.length ? (
                <div className="grid grid-cols-3 gap-2">
                    {d.attachments.map((a) => (
                        <div key={a.id} className="overflow-hidden rounded-lg border border-border">
                            {a.is_image ? (
                                <img src={a.url} alt={a.alt_text ?? a.original_name} className="h-24 w-full object-cover" />
                            ) : (
                                <span className="flex h-24 w-full items-center justify-center bg-muted text-muted-foreground">
                                    <Paperclip className="h-5 w-5" />
                                </span>
                            )}
                            <div className="flex items-center justify-between gap-1 px-1.5 py-1">
                                <span className="block truncate text-[11px] text-muted-foreground" title={a.original_name}>{a.original_name}</span>
                                <span className="flex items-center gap-1">
                                    <a href={`/fleet-assets/incidents/${d.id}/attachments/${a.id}/download`} className="rounded px-1 py-0.5 text-primary hover:bg-muted" aria-label="Download">
                                        <Download className="h-3 w-3" />
                                    </a>
                                    {canUpload ? (
                                        <button type="button" onClick={() => router.delete(`/fleet-assets/incidents/${d.id}/attachments/${a.id}`, { preserveScroll: true })} className="rounded px-1 py-0.5 text-status-critical hover:bg-muted" aria-label="Remove">
                                            <Trash2 className="h-3 w-3" />
                                        </button>
                                    ) : null}
                                </span>
                            </div>
                            {a.notes ? <p className="px-1.5 pb-1 text-[10px] text-muted-foreground">{a.notes}</p> : null}
                        </div>
                    ))}
                </div>
            ) : (
                <EmptyState icon={Paperclip} text="No photos or documents attached yet." />
            )}
        </div>
    );
}

function InvestigationSection({ d }: { d: FleetIncidentDetail }) {
    const completeFollowup = (fid: number) => router.post(`/fleet-assets/incidents/${d.id}/followups/${fid}/complete`, {}, { preserveScroll: true });
    return (
        <div className="flex flex-col gap-4">
            <ReviewCard icon={Search} title="Investigation">
                <ReviewRow label="Root cause" value={d.root_cause} />
                <ReviewRow label="Contributing factors" value={d.contributing_factors?.join(', ')} />
                <ReviewRow label="Corrective actions" value={d.corrective_actions} />
                <ReviewRow label="Completed at" value={d.investigation_completed_at ? formatDateTime(d.investigation_completed_at) : undefined} />
            </ReviewCard>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Follow-ups</p>
                {d.followups.length ? (
                    <div className="flex flex-col gap-2">
                        {d.followups.map((f) => (
                            <div key={f.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                                <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${f.is_completed ? 'text-status-success' : 'text-status-warning'}`} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-foreground">{f.notes || 'Follow-up task'}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {f.assigned_to?.name ?? 'Unassigned'}
                                        {f.due_at ? ` · due ${formatDateTime(f.due_at)}` : ''}
                                        {f.completed_at ? ` · completed ${formatDateTime(f.completed_at)}` : ''}
                                    </p>
                                </div>
                                {!f.is_completed && d.can.manage ? (
                                    <Button variant="outline" size="sm" onClick={() => completeFollowup(f.id)}>
                                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Complete
                                    </Button>
                                ) : f.is_completed ? (
                                    <span className="inline-flex items-center gap-1 text-xs font-medium text-status-success">
                                        <CheckCircle2 className="h-3.5 w-3.5" /> Done
                                    </span>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState icon={ListTodo} text="No follow-ups recorded." />
                )}
            </div>
        </div>
    );
}

function LinkedSection({ d }: { d: FleetIncidentDetail }) {
    const empty = !d.hs_event && !d.asset && !d.client_incidents.length;
    return (
        <div className="flex flex-col gap-2">
            {d.hs_event ? (
                <LinkedRow icon={ShieldAlert} title="H&S event" sub={`${d.hs_event.reference ?? `#${d.hs_event.id}`} · ${titleCase(d.hs_event.status)}`} href={`/health-safety/events/${d.hs_event.id}`} />
            ) : null}
            {d.hs_event?.control_room_alert_id ? (
                <LinkedRow icon={ShieldAlert} title="Control Room alert" sub="Active operational alert" href={`/control-room/alerts/${d.hs_event.control_room_alert_id}`} />
            ) : null}
            {d.asset ? (
                <LinkedRow icon={Truck} title="Asset register" sub={`${d.asset.name}${d.asset.registration_number ? ` · ${d.asset.registration_number}` : ''}`} href={`/fleet-assets/assets/${d.asset.id}`} />
            ) : null}
            {d.asset?.hr_asset ? (
                d.can.view_hr_assets ? (
                    <LinkedRow
                        icon={Briefcase}
                        title="HR asset record"
                        sub={d.asset.hr_asset.holder_name ? `HR: assigned to ${d.asset.hr_asset.holder_name}` : 'Tracked in HR Asset Register'}
                        href={`/hr/assets/${d.asset.hr_asset.id}`}
                    />
                ) : (
                    <div className="flex items-center gap-3 rounded-lg border border-border p-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                            <Briefcase className="h-4 w-4 text-muted-foreground" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">HR asset record</p>
                            <p className="truncate text-xs text-muted-foreground">
                                {d.asset.hr_asset.holder_name ? `HR: assigned to ${d.asset.hr_asset.holder_name}` : 'Tracked in HR Asset Register'}
                            </p>
                        </div>
                    </div>
                )
            ) : null}
            {d.client_incidents.map((ci) => (
                <LinkedRow key={ci.id} icon={User} title="Client incident (resident aboard)" sub={`${ci.client?.name ?? 'Resident'} · ${titleCase(ci.status)} · ${titleCase(ci.type)}`} href={`/incidents?incident=${ci.id}`} />
            ))}
            {empty ? <p className="text-sm text-muted-foreground">No linked records.</p> : null}
        </div>
    );
}

function LinkedRow({ icon: Icon, title, sub, href }: { icon: ComponentType<{ className?: string }>; title: string; sub: string; href: string }) {
    return (
        <Link href={href} className="flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
            <ExternalLink className="h-4 w-4 text-muted-foreground" />
        </Link>
    );
}

/* ------------------------------------------------------------------ */
/*  Action panes (in-place workflow sub-forms)                         */
/* ------------------------------------------------------------------ */

function ActionPane({ incidentId, action, d, users, onDone }: { incidentId: number; action: ActionKey; d: FleetIncidentDetail; users: UserOption[]; onDone: () => void }) {
    switch (action) {
        case 'status':
            return <StatusPane incidentId={incidentId} d={d} onDone={onDone} />;
        case 'followup':
            return <FollowupPane incidentId={incidentId} users={users} onDone={onDone} />;
        case 'police_report':
            return <PoliceReportPane incidentId={incidentId} onDone={onDone} />;
        case 'claim':
            return <ClaimPane incidentId={incidentId} d={d} onDone={onDone} />;
        case 'off_road':
            return <OffRoadPane incidentId={incidentId} onDone={onDone} />;
        case 'back_in_service':
            return <BackInServicePane incidentId={incidentId} onDone={onDone} />;
    }
}

function PaneButtons({ onCancel, processing, label = 'Confirm' }: { onCancel: () => void; processing: boolean; label?: string }) {
    return (
        <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onCancel}>
                Cancel
            </Button>
            <Button type="submit" disabled={processing}>
                {label}
            </Button>
        </div>
    );
}

function StatusPane({ incidentId, d, onDone }: { incidentId: number; d: FleetIncidentDetail; onDone: () => void }) {
    const nextStatus = d.status === 'reported' ? 'investigating' : d.status === 'investigating' ? 'resolved' : 'closed';
    const form = useForm<{ status: string; resolution_notes: string }>({ status: nextStatus, resolution_notes: d.resolution_notes ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/status`, { preserveScroll: true, onSuccess: () => onDone() });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={CheckCircle2} title={nextStatus === 'closed' ? 'Close incident' : `Move to ${titleCase(nextStatus)}`} blurb={nextStatus === 'closed' ? 'Resolution notes are required to close. WorkSafe-notifiable events should be notified and any open follow-ups completed first.' : 'Advance the incident lifecycle.'} />
            {nextStatus === 'closed' && d.requires_police_report && !d.police_report_logged_at && !d.traffic_crash_report_reference ? (
                <InfoCard icon={ShieldAlert} tone="warn">No Police report (TCR) has been logged for this injury/fatal crash — Land Transport Act s22.</InfoCard>
            ) : null}
            {nextStatus === 'resolved' || nextStatus === 'closed' ? (
                <Field label="Resolution notes" required={nextStatus === 'closed'} error={form.errors.resolution_notes}>
                    <Textarea rows={4} value={form.data.resolution_notes} onChange={(e) => form.setData('resolution_notes', e.target.value)} placeholder="What was the outcome and how was it resolved?" />
                </Field>
            ) : null}
            <PaneButtons onCancel={onDone} processing={form.processing} />
        </form>
    );
}

function FollowupPane({ incidentId, users, onDone }: { incidentId: number; users: UserOption[]; onDone: () => void }) {
    const form = useForm<{ notes: string; assigned_to_user_id: string; due_at: string }>({ notes: '', assigned_to_user_id: '', due_at: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/followups`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={Plus} title="Add follow-up" blurb="An operational task to track to completion." />
            <Field label="What needs doing" required error={form.errors.notes}>
                <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Assign to" error={form.errors.assigned_to_user_id}>
                    <select value={form.data.assigned_to_user_id} onChange={(e) => form.setData('assigned_to_user_id', e.target.value)} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        <option value="">Unassigned</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Due" error={form.errors.due_at}>
                    <Input type="datetime-local" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </Field>
            </div>
            <PaneButtons onCancel={onDone} processing={form.processing} label="Add follow-up" />
        </form>
    );
}

function PoliceReportPane({ incidentId, onDone }: { incidentId: number; onDone: () => void }) {
    const form = useForm<{ traffic_crash_report_reference: string; police_reference: string; attending_officer: string; reported_at: string }>({ traffic_crash_report_reference: '', police_reference: '', attending_officer: '', reported_at: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/police-report`, { preserveScroll: true, onSuccess: () => onDone() });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={ShieldAlert} title="Log Police report (TCR)" blurb="Land Transport Act 1998 s22 — record the Traffic Crash Report for this injury/fatal crash." />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="TCR reference" error={form.errors.traffic_crash_report_reference}>
                    <Input value={form.data.traffic_crash_report_reference} onChange={(e) => form.setData('traffic_crash_report_reference', e.target.value)} />
                </Field>
                <Field label="Police reference" error={form.errors.police_reference}>
                    <Input value={form.data.police_reference} onChange={(e) => form.setData('police_reference', e.target.value)} />
                </Field>
                <Field label="Attending officer" error={form.errors.attending_officer}>
                    <Input value={form.data.attending_officer} onChange={(e) => form.setData('attending_officer', e.target.value)} />
                </Field>
                <Field label="Reported at" error={form.errors.reported_at}>
                    <Input type="datetime-local" value={form.data.reported_at} onChange={(e) => form.setData('reported_at', e.target.value)} />
                </Field>
            </div>
            <PaneButtons onCancel={onDone} processing={form.processing} label="Log report" />
        </form>
    );
}

function ClaimPane({ incidentId, d, onDone }: { incidentId: number; d: FleetIncidentDetail; onDone: () => void }) {
    const form = useForm<{ insurer_name: string; insurance_reference: string; insurance_excess: string; insurance_amount_sought: string; insurance_claim_status: string }>({
        insurer_name: d.insurer_name ?? '',
        insurance_reference: d.insurance_reference ?? '',
        insurance_excess: d.insurance_excess != null ? String(d.insurance_excess) : '',
        insurance_amount_sought: d.insurance_amount_sought != null ? String(d.insurance_amount_sought) : '',
        insurance_claim_status: d.insurance_claim_status ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/claim`, { preserveScroll: true, onSuccess: () => onDone() });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={CreditCard} title="Log insurance claim" blurb="Record the insurer and claim details." />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Insurer" error={form.errors.insurer_name}>
                    <Input value={form.data.insurer_name} onChange={(e) => form.setData('insurer_name', e.target.value)} />
                </Field>
                <Field label="Claim reference" error={form.errors.insurance_reference}>
                    <Input value={form.data.insurance_reference} onChange={(e) => form.setData('insurance_reference', e.target.value)} />
                </Field>
                <Field label="Excess ($)" error={form.errors.insurance_excess}>
                    <Input type="number" min="0" value={form.data.insurance_excess} onChange={(e) => form.setData('insurance_excess', e.target.value)} />
                </Field>
                <Field label="Amount sought ($)" error={form.errors.insurance_amount_sought}>
                    <Input type="number" min="0" value={form.data.insurance_amount_sought} onChange={(e) => form.setData('insurance_amount_sought', e.target.value)} />
                </Field>
                <Field label="Status" error={form.errors.insurance_claim_status}>
                    <Input value={form.data.insurance_claim_status} onChange={(e) => form.setData('insurance_claim_status', e.target.value)} placeholder="lodged / assessing / approved / settled" />
                </Field>
            </div>
            <PaneButtons onCancel={onDone} processing={form.processing} label="Log claim" />
        </form>
    );
}

function OffRoadPane({ incidentId, onDone }: { incidentId: number; onDone: () => void }) {
    const form = useForm<{ off_road_from: string; off_road_to: string }>({ off_road_from: '', off_road_to: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/off-road`, { preserveScroll: true, onSuccess: () => onDone() });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={CircleSlash} title="Mark off-road (VOR)" blurb="Take the vehicle out of service — surfaces on the Off-road worklist." />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Off-road from" error={form.errors.off_road_from}>
                    <Input type="date" value={form.data.off_road_from} onChange={(e) => form.setData('off_road_from', e.target.value)} />
                </Field>
                <Field label="Expected return" error={form.errors.off_road_to}>
                    <Input type="date" value={form.data.off_road_to} onChange={(e) => form.setData('off_road_to', e.target.value)} />
                </Field>
            </div>
            <PaneButtons onCancel={onDone} processing={form.processing} label="Mark off-road" />
        </form>
    );
}

function BackInServicePane({ incidentId, onDone }: { incidentId: number; onDone: () => void }) {
    const form = useForm<{ service_resumed_at: string }>({ service_resumed_at: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/fleet-assets/incidents/${incidentId}/back-in-service`, { preserveScroll: true, onSuccess: () => onDone() });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={CheckCircle2} title="Return to service" blurb="Mark the vehicle back in service." />
            <Field label="Service resumed" error={form.errors.service_resumed_at}>
                <Input type="date" value={form.data.service_resumed_at} onChange={(e) => form.setData('service_resumed_at', e.target.value)} />
            </Field>
            <PaneButtons onCancel={onDone} processing={form.processing} label="Return to service" />
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function EmptyState({ icon: Icon, text }: { icon: ComponentType<{ className?: string }>; text: string }) {
    return (
        <div className="rounded-xl border border-dashed border-border py-10 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">{text}</p>
        </div>
    );
}
