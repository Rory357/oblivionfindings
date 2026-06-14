/* eslint-disable no-restricted-syntax -- the audit detail drawer is a read-only traceability surface
   (custom-layout bordered sections + cross-link chips, not Card/Button); all colours are tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import {
    AlertOctagon,
    AlertTriangle,
    Check,
    ClipboardCheck,
    FileText,
    Lock,
    Pencil,
    Pill,
    Shield,
    Trash2,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

export type AuditEvent = {
    id: string;
    event_type: string;
    timestamp: string;
    description: string;
    performed_by: string | null;
    witness: string | null;
    witness_required: boolean;
    client_id: number | null;
    client_name: string;
    details: Record<string, unknown>;
    category: string;
    source: string;
    site_id: number | null;
    site_name: string | null;
    outcome: string | null;
    flags: string[];
};

type Meta = { label: string; icon: LucideIcon; cls: string };
export const EVENT_META: Record<string, Meta> = {
    dose_administered: { label: 'Administered', icon: Check, cls: 'bg-status-success-bg text-status-success' },
    dose_refused: { label: 'Refused', icon: XCircle, cls: 'bg-status-warning-bg text-status-warning' },
    dose_missed: { label: 'Missed', icon: AlertTriangle, cls: 'bg-status-critical-bg text-status-critical' },
    medication_started: { label: 'Started', icon: Pill, cls: 'bg-status-info-bg text-status-info' },
    medication_ceased: { label: 'Ceased', icon: XCircle, cls: 'bg-muted text-muted-foreground' },
    medication_changed: { label: 'Changed', icon: Pencil, cls: 'bg-status-info-bg text-status-info' },
    cd_given: { label: 'CD given', icon: Lock, cls: 'bg-accent text-primary' },
    cd_balance_check: { label: 'CD balance', icon: Shield, cls: 'bg-accent text-primary' },
    destruction: { label: 'Destroyed', icon: Trash2, cls: 'bg-status-warning-bg text-status-warning' },
    prescriber_order: { label: 'Prescriber order', icon: FileText, cls: 'bg-status-info-bg text-status-info' },
    review_completed: { label: 'Review', icon: ClipboardCheck, cls: 'bg-status-info-bg text-status-info' },
    medication_error: { label: 'Error', icon: AlertOctagon, cls: 'bg-status-critical-bg text-status-critical' },
};
export const eventMeta = (t: string): Meta => EVENT_META[t] ?? { label: t.replace(/_/g, ' '), icon: FileText, cls: 'bg-muted text-muted-foreground' };

export const FLAG_META: Record<string, { label: string; cls: string }> = {
    missing_witness: { label: 'No 2nd signature', cls: 'bg-status-critical-bg text-status-critical' },
    omission: { label: 'MAR omission', cls: 'bg-status-critical-bg text-status-critical' },
    no_actor: { label: 'Not attributed', cls: 'bg-status-warning-bg text-status-warning' },
    no_reason: { label: 'No reason code', cls: 'bg-status-warning-bg text-status-warning' },
};

const fmtDateTime = (iso: string) => new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const CATEGORY_LINK: Record<string, { href: string; label: string }> = {
    doses: { href: '/emar/mar', label: 'MAR chart' },
    controlled: { href: '/emar/controlled', label: 'CD register' },
    clinical: { href: '/emar/reviews', label: 'Reviews' },
    stock: { href: '/emar/destructions', label: 'Destruction register' },
    errors: { href: '/emar/errors', label: 'Error register' },
};

export function MedicationEventDrawer({ event, onClose }: { event: AuditEvent; onClose: () => void }) {
    const meta = eventMeta(event.event_type);
    const Icon = meta.icon;
    const detailRows = Object.entries(event.details ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '');
    const link = CATEGORY_LINK[event.category];
    const primaryHref = event.category === 'doses' ? '/emar/mar' : link?.href ?? '/emar';

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={`${meta.label} · ${event.source}`}
            description={event.description}
            railIcon={Icon}
            railTitle={meta.label}
            railSubtitle={`${event.source} · append-only`}
            steps={[{ key: 'detail', label: 'Traceability', blurb: 'Read-only', icon: FileText }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>Close</Button>
                    <a href={primaryHref}><Button>Open in {link?.label ?? 'eMAR'}</Button></a>
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${meta.cls}`}><Icon className="h-3.5 w-3.5" />{meta.label}</span>
                <span className="text-xs text-muted-foreground">{fmtDateTime(event.timestamp)}</span>
                {event.flags.map((f) => <span key={f} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${FLAG_META[f]?.cls ?? 'bg-muted text-muted-foreground'}`}>{FLAG_META[f]?.label ?? f}</span>)}
            </div>

            {event.flags.includes('missing_witness') && (
                <div className="mt-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/60 px-3 py-2 text-xs text-status-critical">Controlled-drug transaction without a recorded second signature — investigate and countersign in the CD register.</div>
            )}
            {event.flags.includes('omission') && (
                <div className="mt-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/60 px-3 py-2 text-xs text-status-critical">A dose was not given and not coded — a MAR omission must be reconciled with a reason.</div>
            )}

            <Section title="What happened">
                <div className="rounded-lg border px-4">
                    <SummaryRow label="Client" value={event.client_name} />
                    {event.site_name && <SummaryRow label="Site" value={event.site_name} />}
                    {event.outcome && <SummaryRow label="Outcome" value={String(event.outcome)} />}
                    {detailRows.map(([k, v]) => <SummaryRow key={k} label={k.replace(/_/g, ' ')} value={String(v)} />)}
                </div>
            </Section>

            <Section title="People & sign-off">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className={`rounded-lg border px-3 py-2.5 ${event.performed_by ? '' : 'border-status-warning/40 bg-status-warning-bg/40'}`}>
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Performed by</div>
                        <div className="text-sm font-medium">{event.performed_by ?? 'Not attributed to a staff member'}</div>
                    </div>
                    {event.witness_required && (
                        <div className={`rounded-lg border px-3 py-2.5 ${event.witness ? 'border-status-success/40 bg-status-success-bg/40' : 'border-status-critical/40 bg-status-critical-bg/40'}`}>
                            <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Witness (2nd signature)</div>
                            <div className={`text-sm font-medium ${event.witness ? '' : 'text-status-critical'}`}>{event.witness ?? 'Required — missing'}</div>
                        </div>
                    )}
                </div>
            </Section>

            <Section title="Record integrity">
                <div className="rounded-lg border px-4">
                    <SummaryRow label="Recorded at" value={fmtDateTime(event.timestamp)} />
                    <SummaryRow label="Edited since" value="Never — append-only" />
                </div>
            </Section>

            <Section title="Linked records">
                <div className="flex flex-wrap gap-2">
                    {link && <a href={link.href} className="rounded-full border px-3 py-1 text-xs font-medium text-primary hover:bg-accent">{link.label}</a>}
                    {event.client_id && <a href={`/clients/${event.client_id}`} className="rounded-full border px-3 py-1 text-xs font-medium text-primary hover:bg-accent">Client profile</a>}
                </div>
            </Section>
        </MedsWizardDialog>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="mt-5">
            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</div>
            {children}
        </div>
    );
}
